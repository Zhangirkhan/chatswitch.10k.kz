<?php

declare(strict_types=1);

namespace App\Services\SuperAdmin;

use App\Models\Chat;
use App\Models\ChatAssignment;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Department;
use App\Models\Funnel;
use App\Models\FunnelAiScenario;
use App\Models\FunnelStage;
use App\Models\FunnelStageAiRule;
use App\Models\KnowledgeRule;
use App\Models\Message;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Models\WhatsappSession;
use App\Services\Billing\SubscriptionLifecycleService;
use App\Services\Company\CompanyOnboardingService;
use App\Services\Company\DemoChatsFactory;
use App\Services\Tenancy\TenantNginxMapService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Spatie\Permission\Models\Role;

final class DemoTenantPopulationService
{
    private const DEMO_PASSWORD = 'Demo2026!';

    public function __construct(
        private readonly CompanyOnboardingService $onboarding,
        private readonly DemoChatsFactory $demoChats,
        private readonly SubscriptionLifecycleService $subscriptions,
        private readonly CompanyModuleSettingsService $modules,
        private readonly SuperAdminAuditLogger $audit,
    ) {}

    /**
     * @return array{company: Company, stats: array<string, int|string>}
     */
    public function populate(?User $actor = null): array
    {
        return $this->populateCompany($this->resolveDemoCompany(), $actor);
    }

    /**
     * @return array{company: Company, stats: array<string, int|string>}
     */
    public function populateCompany(Company $company, ?User $actor = null): array
    {
        $isDemo = $this->isDemoCompany($company);

        $stats = DB::transaction(function () use ($company, $actor, $isDemo): array {
            $this->wipeOperationalData($company, preserveUsers: ! $isDemo);

            if ($isDemo) {
                $company->forceFill([
                    'name' => 'Accel Demo',
                    'description' => 'Демонстрационный тенант: чаты, AI-воронка, отделы, каталог и база знаний для презентации Accel.',
                    'phone' => '+77001230000',
                    'email' => 'demo@accel.kz',
                    'website' => 'https://accel.kz',
                    'is_active' => true,
                ])->save();
            } else {
                $company->forceFill(['is_active' => true])->save();
            }

            $plan = $this->subscriptions->defaultPlan();
            $company->update(['plan_id' => $plan->id]);
            $this->subscriptions->activatePaid($company->fresh(), $plan, now()->addYear());

            $owner = $company->fresh()->owner;
            if (! $owner instanceof User) {
                $owner = $this->seedUsers($company);
                $company->update(['owner_user_id' => $owner->id]);
            } else {
                $owner->syncRoles(['administrator']);
            }

            $this->onboarding->bootstrap($company->fresh(), $owner);
            $this->modules->ensureDefaults($company->fresh());

            $sessions = $this->seedWhatsappSessions($company);
            $this->linkUsersToSessions($company, $sessions, $owner);

            $chatStats = $this->demoChats->seedForCompany($company->fresh());
            $this->assignManagersToChats($company->fresh(), $owner);

            $funnelStages = FunnelStage::query()
                ->whereIn('funnel_id', Funnel::query()->where('company_id', $company->id)->pluck('id'))
                ->count();

            $this->audit->log($company, $actor, 'demo.populated', $company, [
                'chats' => $chatStats['chats'],
                'messages' => $chatStats['messages'],
            ]);

            $ownerEmail = $owner->email;
            $loginHint = $isDemo ? 'demo@accel.kz' : $ownerEmail;

            return [
                'users' => User::query()->withoutGlobalScope('tenant')->where('company_id', $company->id)->count(),
                'departments' => Department::query()->withoutGlobalScope('tenant')->where('company_id', $company->id)->where('is_active', true)->count(),
                'funnel_stages' => $funnelStages,
                'whatsapp_sessions' => count($sessions),
                'chats' => $chatStats['chats'],
                'messages' => $chatStats['messages'],
                'login' => $loginHint,
                'password' => $isDemo ? self::DEMO_PASSWORD : '(пароль владельца при создании компании)',
                'tenant_url' => $company->fresh()->tenantUrl('/login'),
            ];
        });

        try {
            app(TenantNginxMapService::class)->writeMapFile();
        } catch (\Throwable $e) {
            report($e);
        }

        return [
            'company' => $company->fresh(['owner', 'plan']),
            'stats' => $stats,
        ];
    }

    public function resolveDemoCompany(): Company
    {
        $slug = (string) config('tenancy.fallback_slug', 'demo');

        $company = Company::query()
            ->withoutGlobalScope('tenant')
            ->where('slug', $slug)
            ->first();

        if ($company === null) {
            throw new RuntimeException("Демо-тенант со slug «{$slug}» не найден.");
        }

        return $company;
    }

    private function isDemoCompany(Company $company): bool
    {
        return $company->slug === (string) config('tenancy.fallback_slug', 'demo');
    }

    private function wipeOperationalData(Company $company, bool $preserveUsers = false): void
    {
        $companyId = $company->id;

        $sessionIds = WhatsappSession::query()
            ->withoutGlobalScope('tenant')
            ->where('company_id', $companyId)
            ->pluck('id');

        $chatIds = Chat::query()
            ->withoutGlobalScope('tenant')
            ->where(function ($query) use ($companyId, $sessionIds): void {
                $query->where('company_id', $companyId);
                if ($sessionIds->isNotEmpty()) {
                    $query->orWhereIn('whatsapp_session_id', $sessionIds);
                }
            })
            ->pluck('id');

        if ($chatIds->isNotEmpty()) {
            Message::query()->whereIn('chat_id', $chatIds)->delete();
            ChatAssignment::query()->whereIn('chat_id', $chatIds)->delete();
            Chat::query()->withoutGlobalScope('tenant')->whereIn('id', $chatIds)->delete();
        }

        WhatsappSession::query()->withoutGlobalScope('tenant')->where('company_id', $companyId)->delete();

        $funnelIds = Funnel::query()->withoutGlobalScope('tenant')->where('company_id', $companyId)->pluck('id');
        if ($funnelIds->isNotEmpty()) {
            FunnelStageAiRule::query()->whereIn('funnel_id', $funnelIds)->delete();
            FunnelAiScenario::query()->whereIn('funnel_id', $funnelIds)->delete();
            FunnelStage::query()->whereIn('funnel_id', $funnelIds)->delete();
            Funnel::query()->withoutGlobalScope('tenant')->whereIn('id', $funnelIds)->delete();
        }

        KnowledgeRule::query()->withoutGlobalScope('tenant')->where('company_id', $companyId)->delete();
        Product::query()->withoutGlobalScope('tenant')->where('company_id', $companyId)->delete();
        Service::query()->withoutGlobalScope('tenant')->where('company_id', $companyId)->delete();

        $departmentIds = Department::query()
            ->withoutGlobalScope('tenant')
            ->where('company_id', $companyId)
            ->pluck('id');

        if ($departmentIds->isNotEmpty()) {
            DB::table('department_user')->whereIn('department_id', $departmentIds)->delete();
            DB::table('department_funnel')->whereIn('department_id', $departmentIds)->delete();
            DB::table('department_funnel_stage')->whereIn('department_id', $departmentIds)->delete();
            Department::query()->withoutGlobalScope('tenant')->whereIn('id', $departmentIds)->delete();
        }

        Contact::query()->withoutGlobalScope('tenant')->where('company_id', $companyId)->delete();
        DB::table('company_contact')->where('company_id', $companyId)->delete();

        if (! $preserveUsers) {
            $userIds = User::query()
                ->withoutGlobalScope('tenant')
                ->where('company_id', $companyId)
                ->where('is_super_admin', false)
                ->pluck('id');

            if ($userIds->isNotEmpty()) {
                DB::table('model_has_roles')->where('model_type', User::class)->whereIn('model_id', $userIds)->delete();
                DB::table('user_whatsapp_session')->whereIn('user_id', $userIds)->delete();
                User::query()->withoutGlobalScope('tenant')->whereIn('id', $userIds)->delete();
            }
        }
    }

    private function seedUsers(Company $company): User
    {
        Role::findOrCreate('administrator', 'web');
        Role::findOrCreate('manager', 'web');
        Role::findOrCreate('employee', 'web');

        $defs = [
            [
                'name' => 'Айгуль Демо',
                'email' => 'demo@accel.kz',
                'role' => 'administrator',
            ],
            [
                'name' => 'Марат Продажи',
                'email' => 'sales@demo.accel.kz',
                'role' => 'manager',
            ],
            [
                'name' => 'Дина Операции',
                'email' => 'ops@demo.accel.kz',
                'role' => 'manager',
            ],
            [
                'name' => 'Серик Поддержка',
                'email' => 'support@demo.accel.kz',
                'role' => 'employee',
            ],
        ];

        $owner = null;

        foreach ($defs as $def) {
            $user = User::query()->withoutGlobalScope('tenant')->updateOrCreate(
                [
                    'company_id' => $company->id,
                    'email' => $def['email'],
                ],
                [
                    'name' => $def['name'],
                    'password' => Hash::make(self::DEMO_PASSWORD),
                    'is_active' => true,
                    'is_super_admin' => false,
                ],
            );

            $user->syncRoles([$def['role']]);

            if ($def['role'] === 'administrator') {
                $owner = $user;
            }
        }

        if (! $owner instanceof User) {
            throw new RuntimeException('Не удалось создать владельца демо-тенанта.');
        }

        return $owner;
    }

    /**
     * @return array<int, WhatsappSession>
     */
    private function seedWhatsappSessions(Company $company): array
    {
        $prefix = $this->isDemoCompany($company) ? 'demo' : $company->slug;

        $defs = [
            [
                'session_name' => "{$prefix}-main",
                'display_name' => 'Главный WhatsApp',
                'phone_number' => '+77001000001',
                'display_color' => '#0f766e',
            ],
            [
                'session_name' => "{$prefix}-sales",
                'display_name' => 'Продажи',
                'phone_number' => '+77001000002',
                'display_color' => '#2563eb',
            ],
            [
                'session_name' => "{$prefix}-support",
                'display_name' => 'Поддержка',
                'phone_number' => '+77001000003',
                'display_color' => '#7c3aed',
            ],
        ];

        $sessions = [];

        foreach ($defs as $def) {
            $isDemo = $this->isDemoCompany($company);

            $sessions[] = WhatsappSession::query()->withoutGlobalScope('tenant')->create([
                'company_id' => $company->id,
                'session_name' => $def['session_name'],
                'display_name' => $def['display_name'],
                'phone_number' => $def['phone_number'],
                'display_color' => $def['display_color'],
                'status' => $isDemo ? 'connected' : 'disconnected',
                'connected_at' => $isDemo ? now() : null,
                'wa_name' => $isDemo ? $def['display_name'] : null,
                'desired_state' => 'active',
                'is_active' => true,
            ]);
        }

        return $sessions;
    }

    /**
     * @param  array<int, WhatsappSession>  $sessions
     */
    private function linkUsersToSessions(Company $company, array $sessions, User $owner): void
    {
        $sales = User::query()->withoutGlobalScope('tenant')->where('company_id', $company->id)->where('email', 'sales@demo.accel.kz')->first();
        $ops = User::query()->withoutGlobalScope('tenant')->where('company_id', $company->id)->where('email', 'ops@demo.accel.kz')->first();
        $support = User::query()->withoutGlobalScope('tenant')->where('company_id', $company->id)->where('email', 'support@demo.accel.kz')->first();

        $sessionIds = collect($sessions)->pluck('id')->all();

        foreach ([$owner, $sales, $ops, $support] as $user) {
            if ($user instanceof User) {
                $user->whatsappSessions()->sync($sessionIds);
            }
        }

        $salesDept = Department::query()->withoutGlobalScope('tenant')
            ->where('company_id', $company->id)->where('name', 'Отдел продаж')->first();
        $opsDept = Department::query()->withoutGlobalScope('tenant')
            ->where('company_id', $company->id)->where('name', 'Операционный отдел')->first();

        if ($salesDept && $owner) {
            $owner->syncDepartments([$salesDept->id]);
        }
        if ($salesDept && $sales) {
            $sales->syncDepartments([$salesDept->id]);
        }
        if ($opsDept && $ops) {
            $ops->syncDepartments([$opsDept->id]);
        }
        if ($salesDept && $support) {
            $support->syncDepartments([$salesDept->id]);
        }
    }

    private function assignManagersToChats(Company $company, User $owner): void
    {
        $manager = User::query()->withoutGlobalScope('tenant')
            ->where('company_id', $company->id)
            ->where('email', 'sales@demo.accel.kz')
            ->first();

        if ($manager === null) {
            return;
        }

        Chat::query()
            ->withoutGlobalScope('tenant')
            ->where('company_id', $company->id)
            ->orderBy('id')
            ->limit(4)
            ->get()
            ->each(function (Chat $chat) use ($manager, $owner): void {
                ChatAssignment::query()->updateOrCreate(
                    ['chat_id' => $chat->id],
                    [
                        'user_id' => $manager->id,
                        'assigned_by' => $owner->id,
                    ],
                );
            });
    }
}
