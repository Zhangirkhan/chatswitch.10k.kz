<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

use App\Jobs\IssueTenantCertificateJob;
use App\Models\Company;
use App\Models\Department;
use App\Models\Funnel;
use App\Models\FunnelAiScenario;
use App\Models\FunnelStageAiRule;
use App\Models\KnowledgeRule;
use App\Models\User;
use App\Models\WhatsappSession;
use App\Services\AI\AiReadinessService;
use App\Services\Company\CompanyOnboardingService;
use App\Services\WhatsappService;
use App\Services\WhatsappSessionHealService;
use App\Support\TenantRoles;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\Process\Process;

final class TenantDoctorService
{
    public function __construct(
        private readonly AiReadinessService $readiness,
        private readonly WhatsappService $whatsapp,
        private readonly WhatsappSessionHealService $healService,
        private readonly CompanyOnboardingService $onboarding,
        private readonly TenantNginxMapService $nginxMap,
    ) {}

    /**
     * @return array{
     *     company_id: int,
     *     slug: string,
     *     ok: bool,
     *     groups: array<string, array{ok: bool, checks: list<array{key: string, ok: bool, severity: string, message: string, details?: array<string, mixed>, fixable?: bool}>}>
     * }
     */
    public function diagnose(Company $company, bool $includeInfra = true): array
    {
        $groups = [
            'data' => $this->checkData($company),
            'permissions' => $this->checkPermissions($company),
            'dns_ssl' => $this->checkDnsSsl($company),
            'whatsapp' => $this->checkWhatsapp($company),
            'readiness' => $this->checkReadiness($company),
            'queues' => $this->checkQueues($company),
        ];

        if ($includeInfra) {
            $groups = ['infra' => $this->checkInfra()] + $groups;
        }

        $ok = collect($groups)->every(
            static fn (array $group): bool => collect($group['checks'])->every(
                static fn (array $check): bool => $check['ok'] || $check['severity'] !== 'critical',
            ),
        );

        return [
            'company_id' => $company->id,
            'slug' => $company->slug,
            'ok' => $ok,
            'groups' => $groups,
        ];
    }

    /**
     * @param  array<string, array{ok: bool, checks: list<array<string, mixed>>}>  $groups
     * @return list<string>
     */
    public function fix(Company $company, array &$groups): array
    {
        $actions = [];

        if ($this->groupNeedsFix($groups, 'infra')) {
            foreach ($groups['infra']['checks'] ?? [] as $check) {
                if (($check['key'] ?? '') === 'contacts_whatsapp_index' && ! ($check['ok'] ?? true)) {
                    Artisan::call('migrate', [
                        '--force' => true,
                        '--path' => 'database/migrations/2026_06_10_150000_contacts_whatsapp_id_unique_per_company.php',
                    ]);
                    $actions[] = 'migrate-contacts-whatsapp-index';
                }
            }
        }

        if ($this->groupNeedsFix($groups, 'data')) {
            $owner = $this->resolveOwner($company);
            $this->withTenantContext($company, function () use ($company, $owner): void {
                $this->onboarding->bootstrap($company, $owner);
                TenantRoles::ensureDefaultRolesForCompany($company);
            });
            $this->readiness->invalidateCounts($company->id);
            $actions[] = 'bootstrap-ai-defaults';
        }

        if ($this->groupNeedsFix($groups, 'permissions')) {
            $this->withTenantContext($company, function () use ($company): void {
                TenantRoles::ensureDefaultRolesForCompany($company);
                Artisan::call('tenants:migrate-roles', ['--company' => (string) $company->id]);
            });
            app(PermissionRegistrar::class)->forgetCachedPermissions();
            $actions[] = 'migrate-roles';
        }

        if ($this->groupNeedsFix($groups, 'dns_ssl')) {
            $this->nginxMap->writeMapFile();
            $actions[] = 'sync-nginx-map';

            $host = $this->tenantHost($company);
            if (! $this->hostInNginxMap($host)) {
                IssueTenantCertificateJob::dispatchSync($company->slug);
                $actions[] = 'issue-cert';
            }
        }

        if ($this->groupNeedsFix($groups, 'whatsapp')) {
            WhatsappSession::query()
                ->withoutGlobalScope('tenant')
                ->where('company_id', $company->id)
                ->where('desired_state', WhatsappSession::DESIRED_ACTIVE)
                ->orderBy('id')
                ->each(function (WhatsappSession $session) use (&$actions): void {
                    if (! $this->whatsapp->healthReachable()) {
                        return;
                    }

                    try {
                        $result = $this->healService->healSession($session);
                        if ($result === 'healed' || $result === 'skipped_alive') {
                            $this->whatsapp->syncInboundMessages($session->session_name);
                        }
                        $actions[] = 'whatsapp-heal:'.$session->session_name;
                    } catch (\Throwable) {
                        // leave for next doctor run
                    }
                });
        }

        if ($this->groupNeedsFix($groups, 'readiness')) {
            $this->readiness->invalidateCounts($company->id);
            $actions[] = 'invalidate-readiness-cache';
        }

        $groups = $this->diagnose($company)['groups'];

        return array_values(array_unique($actions));
    }

    public function hasCriticalFailures(array $report): bool
    {
        foreach ($report['groups'] ?? [] as $group) {
            foreach ($group['checks'] ?? [] as $check) {
                if (($check['severity'] ?? '') === 'critical' && ! ($check['ok'] ?? false)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return array{ok: bool, checks: list<array<string, mixed>>}
     */
    private function checkInfra(): array
    {
        $checks = [];

        $ping = $this->whatsapp->healthPing();
        $checks[] = [
            'key' => 'whatsapp_node',
            'ok' => $ping['ok'],
            'severity' => 'critical',
            'message' => $ping['ok']
                ? 'WhatsApp Node отвечает ('.($ping['latency_ms'] ?? '?').' ms)'
                : 'WhatsApp Node недоступен',
            'details' => ['body' => $ping['body']],
            'fixable' => false,
        ];

        $tokenOk = (string) config('services.whatsapp.service_token', '') !== ''
            && (string) config('services.whatsapp.service_token', '') === (string) config('services.whatsapp.token', '');
        $checks[] = [
            'key' => 'whatsapp_tokens',
            'ok' => $tokenOk || app()->runningUnitTests(),
            'severity' => 'warning',
            'message' => $tokenOk ? 'Токены WhatsApp service согласованы' : 'WHATSAPP_SERVICE_TOKEN может не совпадать с Node LARAVEL_API_TOKEN',
            'fixable' => false,
        ];

        foreach (['accel-queue', 'accel-queue-provisioning', 'accel-queue-whatsapp'] as $program) {
            $running = $this->supervisorProgramRunning($program);
            $checks[] = [
                'key' => 'supervisor:'.$program,
                'ok' => $running !== false ? $running : true,
                'severity' => $program === 'accel-queue-provisioning' ? 'critical' : 'warning',
                'message' => match (true) {
                    $running === true => "Supervisor {$program}: RUNNING",
                    $running === false => "Supervisor {$program}: не запущен",
                    default => "Supervisor {$program}: статус неизвестен (supervisorctl недоступен)",
                },
                'fixable' => false,
            ];
        }

        $contactsIndexOk = $this->contactsWhatsappIndexPerTenant();
        $checks[] = [
            'key' => 'contacts_whatsapp_index',
            'ok' => $contactsIndexOk,
            'severity' => 'critical',
            'message' => $contactsIndexOk
                ? 'Индекс contacts (company_id, whatsapp_id) настроен'
                : 'Устаревший global unique на contacts.whatsapp_id — входящие WhatsApp могут теряться между тенантами',
            'fixable' => true,
        ];

        return $this->groupResult($checks);
    }

    private function contactsWhatsappIndexPerTenant(): bool
    {
        if (! Schema::hasTable('contacts') || ! Schema::hasColumn('contacts', 'company_id')) {
            return true;
        }

        $indexes = Schema::getConnection()->getSchemaBuilder()->getIndexes('contacts');
        $names = collect($indexes)->pluck('name')->filter()->all();

        if (in_array('contacts_whatsapp_id_unique', $names, true)) {
            return false;
        }

        return in_array('contacts_company_whatsapp_unique', $names, true);
    }

    /**
     * @return array{ok: bool, checks: list<array<string, mixed>>}
     */
    private function checkDnsSsl(Company $company): array
    {
        $host = $this->tenantHost($company);
        $inMap = $this->hostInNginxMap($host);

        $checks = [[
            'key' => 'nginx_map',
            'ok' => $inMap,
            'severity' => 'critical',
            'message' => $inMap ? "{$host} в nginx map" : "{$host} отсутствует в nginx map",
            'fixable' => true,
        ]];

        if (! app()->runningUnitTests()) {
            $httpsOk = false;
            $httpsStatus = null;
            try {
                $response = Http::timeout(5)->get('https://'.$host.'/login');
                $httpsOk = $response->successful() || $response->status() === 302;
                $httpsStatus = $response->status();
            } catch (\Throwable) {
                $httpsOk = false;
            }

            $checks[] = [
                'key' => 'https',
                'ok' => $httpsOk,
                'severity' => 'warning',
                'message' => $httpsOk
                    ? "HTTPS {$host} доступен ({$httpsStatus})"
                    : "HTTPS {$host} недоступен",
                'fixable' => true,
            ];
        }

        return $this->groupResult($checks);
    }

    /**
     * @return array{ok: bool, checks: list<array<string, mixed>>}
     */
    private function checkData(Company $company): array
    {
        $companyId = $company->id;

        $funnels = Funnel::query()->withoutGlobalScope('tenant')->where('company_id', $companyId)->where('is_active', true)->count();
        $stages = (int) DB::table('funnel_stages as fs')
            ->join('funnels as f', 'f.id', '=', 'fs.funnel_id')
            ->where('f.company_id', $companyId)
            ->count();
        $scenarios = FunnelAiScenario::query()->withoutGlobalScope('tenant')->where('company_id', $companyId)->where('enabled', true)->count();
        $knowledge = KnowledgeRule::query()->withoutGlobalScope('tenant')->where('company_id', $companyId)->where('is_active', true)->count();
        $departments = Department::query()->withoutGlobalScope('tenant')->where('company_id', $companyId)->where('is_active', true)->count();
        $owner = $this->resolveOwner($company);

        $checks = [
            [
                'key' => 'owner',
                'ok' => $owner instanceof User,
                'severity' => 'critical',
                'message' => $owner instanceof User ? "Владелец: {$owner->email}" : 'Владелец не назначен',
                'fixable' => false,
            ],
            [
                'key' => 'funnels',
                'ok' => $funnels >= 1,
                'severity' => 'critical',
                'message' => "Воронки: {$funnels} (нужно ≥1)",
                'fixable' => true,
            ],
            [
                'key' => 'stages',
                'ok' => $stages >= 5,
                'severity' => 'critical',
                'message' => "Этапы воронки: {$stages} (нужно ≥5)",
                'fixable' => true,
            ],
            [
                'key' => 'scenarios',
                'ok' => $scenarios >= 1,
                'severity' => 'critical',
                'message' => "AI-сценарии: {$scenarios} (нужно ≥1)",
                'fixable' => true,
            ],
            [
                'key' => 'knowledge',
                'ok' => $knowledge >= 3,
                'severity' => 'warning',
                'message' => "Правила базы знаний: {$knowledge} (нужно ≥3)",
                'fixable' => true,
            ],
            [
                'key' => 'departments',
                'ok' => $departments >= 1,
                'severity' => 'warning',
                'message' => "Отделы: {$departments}",
                'fixable' => true,
            ],
        ];

        if ($owner instanceof User && $owner->department_id === null) {
            $checks[] = [
                'key' => 'owner_department',
                'ok' => false,
                'severity' => 'warning',
                'message' => 'Владелец не привязан к отделу продаж',
                'fixable' => true,
            ];
        }

        return $this->groupResult($checks);
    }

    /**
     * @return array{ok: bool, checks: list<array<string, mixed>>}
     */
    private function checkPermissions(Company $company): array
    {
        $teamKey = config('permission.column_names.team_foreign_key');
        $adminRole = Role::query()
            ->where('name', 'administrator')
            ->where('guard_name', 'web')
            ->where($teamKey, $company->id)
            ->first();

        $permissionCount = $adminRole !== null
            ? $adminRole->permissions()->count()
            : 0;

        $ownerOk = false;
        $owner = $this->resolveOwner($company);
        if ($owner instanceof User) {
            $previousTeamId = app(PermissionRegistrar::class)->getPermissionsTeamId();
            setPermissionsTeamId($company->id);
            try {
                $ownerOk = $owner->hasRole('administrator') && $owner->can('settings.manage');
            } finally {
                setPermissionsTeamId($previousTeamId);
            }
        }

        $checks = [
            [
                'key' => 'admin_role',
                'ok' => $adminRole !== null && $permissionCount > 0,
                'severity' => 'critical',
                'message' => $adminRole !== null
                    ? "Роль administrator: {$permissionCount} permission(s)"
                    : 'Роль administrator для компании не найдена',
                'fixable' => true,
            ],
            [
                'key' => 'owner_admin',
                'ok' => $ownerOk,
                'severity' => 'critical',
                'message' => $ownerOk
                    ? 'Владелец — administrator с settings.manage'
                    : 'У владельца нет administrator / settings.manage',
                'fixable' => true,
            ],
        ];

        return $this->groupResult($checks);
    }

    /**
     * @return array{ok: bool, checks: list<array<string, mixed>>}
     */
    private function checkWhatsapp(Company $company): array
    {
        $sessions = WhatsappSession::query()
            ->withoutGlobalScope('tenant')
            ->where('company_id', $company->id)
            ->orderBy('id')
            ->get();

        if ($sessions->isEmpty()) {
            return $this->groupResult([[
                'key' => 'sessions',
                'ok' => true,
                'severity' => 'info',
                'message' => 'WhatsApp-сессий нет (ожидаемо до подключения)',
                'fixable' => false,
            ]]);
        }

        $nodeUp = $this->whatsapp->healthReachable();
        $checks = [];

        foreach ($sessions as $session) {
            $dbConnected = in_array($session->status, ['connected', 'ready', 'authenticated'], true);
            $alive = false;
            $verify = [];

            if ($nodeUp) {
                try {
                    $verify = $this->whatsapp->verifySession($session->session_name);
                    $alive = (bool) ($verify['alive'] ?? false);
                } catch (\Throwable) {
                    $alive = false;
                }
            }

            $ok = $session->desired_state === WhatsappSession::DESIRED_LOGGED_OUT
                || ! $dbConnected
                || $alive;

            $checks[] = [
                'key' => 'session:'.$session->session_name,
                'ok' => $ok,
                'severity' => $dbConnected && ! $alive ? 'critical' : 'info',
                'message' => sprintf(
                    '[%s] DB=%s, Node alive=%s, desired=%s',
                    $session->session_name,
                    $session->status,
                    $alive ? 'yes' : 'no',
                    $session->desired_state,
                ),
                'details' => ['verify' => $verify],
                'fixable' => $dbConnected && ! $alive && $session->desired_state === WhatsappSession::DESIRED_ACTIVE,
            ];
        }

        return $this->groupResult($checks);
    }

    /**
     * @return array{ok: bool, checks: list<array<string, mixed>>}
     */
    private function checkReadiness(Company $company): array
    {
        $this->readiness->invalidateCounts($company->id);
        $readiness = $this->readiness->evaluate($company->id);
        $blockers = collect($readiness['checks'] ?? [])
            ->reject(fn (array $c): bool => $c['ok'])
            ->pluck('label')
            ->values()
            ->all();

        $checks = [[
            'key' => 'score',
            'ok' => ($readiness['score'] ?? 0) >= AiReadinessService::READY_SCORE,
            'severity' => ($readiness['score'] ?? 0) >= 90 ? 'info' : 'warning',
            'message' => 'AI readiness: '.($readiness['score'] ?? 0).'% — '.($readiness['label'] ?? ''),
            'details' => ['blockers' => $blockers, 'status' => $readiness['status'] ?? null],
            'fixable' => false,
        ]];

        return $this->groupResult($checks);
    }

    /**
     * @return array{ok: bool, checks: list<array<string, mixed>>}
     */
    private function checkQueues(Company $company): array
    {
        $needle = (string) $company->id;
        $slug = $company->slug;

        $recent = DB::table('failed_jobs')
            ->where('failed_at', '>=', now()->subDay())
            ->orderByDesc('id')
            ->limit(200)
            ->get(['id', 'queue', 'payload', 'failed_at']);

        $related = $recent->filter(
            static fn (object $row): bool => str_contains((string) $row->payload, $needle)
                || str_contains((string) $row->payload, $slug),
        );

        $checks = [[
            'key' => 'failed_jobs',
            'ok' => $related->isEmpty(),
            'severity' => $related->isEmpty() ? 'info' : 'warning',
            'message' => $related->isEmpty()
                ? 'Нет failed jobs за 24ч для этого тенанта'
                : 'Failed jobs за 24ч: '.$related->count(),
            'details' => [
                'samples' => $related->take(5)->map(static fn (object $row): array => [
                    'id' => $row->id,
                    'queue' => $row->queue,
                    'failed_at' => $row->failed_at,
                ])->values()->all(),
            ],
            'fixable' => false,
        ]];

        return $this->groupResult($checks);
    }

    /**
     * @param  list<array<string, mixed>>  $checks
     * @return array{ok: bool, checks: list<array<string, mixed>>}
     */
    private function groupResult(array $checks): array
    {
        $ok = collect($checks)->every(
            static fn (array $check): bool => $check['ok'] || ($check['severity'] ?? 'critical') !== 'critical',
        );

        return ['ok' => $ok, 'checks' => $checks];
    }

    /**
     * @param  array<string, array{ok: bool, checks: list<array<string, mixed>>}>  $groups
     */
    private function groupNeedsFix(array $groups, string $key): bool
    {
        return ($groups[$key]['ok'] ?? true) === false;
    }

    private function tenantHost(Company $company): string
    {
        return strtolower($company->slug).'.'.config('tenancy.root_domain', 'accel.kz');
    }

    private function hostInNginxMap(string $host): bool
    {
        $path = $this->nginxMap->mapFilePath();
        if (! is_file($path)) {
            return in_array($host, $this->nginxMap->knownTenantHosts(), true);
        }

        $contents = (string) file_get_contents($path);

        return str_contains($contents, $host)
            || in_array($host, $this->nginxMap->knownTenantHosts(), true);
    }

    /**
     * @param  callable(): void  $callback
     */
    private function withTenantContext(Company $company, callable $callback): void
    {
        $context = app(TenantContext::class);
        $previous = $context->company();
        $context->setCompany($company);
        setPermissionsTeamId($company->id);

        try {
            $callback();
        } finally {
            if ($previous instanceof Company) {
                $context->setCompany($previous);
                setPermissionsTeamId($previous->id);
            }
        }
    }

    private function supervisorProgramRunning(string $program): ?bool
    {
        if (! $this->commandExists('supervisorctl')) {
            return null;
        }

        $process = Process::fromShellCommandline('supervisorctl status '.$program.':* 2>/dev/null');
        $process->setTimeout(5);
        $process->run();

        if (! $process->isSuccessful() && trim($process->getOutput()) === '') {
            $process = Process::fromShellCommandline('supervisorctl status '.$program.' 2>/dev/null');
            $process->setTimeout(5);
            $process->run();
        }

        $output = trim($process->getOutput());
        if ($output === '') {
            return null;
        }

        return str_contains(strtoupper($output), 'RUNNING');
    }

    private function commandExists(string $command): bool
    {
        $process = Process::fromShellCommandline('command -v '.escapeshellarg($command).' 2>/dev/null');
        $process->setTimeout(3);
        $process->run();

        return $process->isSuccessful() && trim($process->getOutput()) !== '';
    }

    private function resolveOwner(Company $company): ?User
    {
        if ($company->owner_user_id === null) {
            return null;
        }

        return User::query()
            ->withoutGlobalScope('tenant')
            ->where('company_id', $company->id)
            ->find($company->owner_user_id);
    }
}
