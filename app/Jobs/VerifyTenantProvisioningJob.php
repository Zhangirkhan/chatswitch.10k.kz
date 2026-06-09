<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Company;
use App\Models\Department;
use App\Models\Funnel;
use App\Models\FunnelAiScenario;
use App\Models\FunnelStage;
use App\Models\KnowledgeRule;
use App\Services\Alerts\TelegramAlertSender;
use App\Services\Tenancy\TenantNginxMapService;
use App\Support\TenantRoles;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Role;

final class VerifyTenantProvisioningJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 60;

    public function __construct(public readonly int $companyId)
    {
        $this->onQueue('provisioning');
    }

    public function handle(TenantNginxMapService $nginxMap, TelegramAlertSender $telegram): void
    {
        $company = Company::query()->withoutGlobalScope('tenant')->find($this->companyId);
        if ($company === null) {
            return;
        }

        $failures = [];

        $funnels = Funnel::query()->withoutGlobalScope('tenant')->where('company_id', $company->id)->count();
        $stages = FunnelStage::query()
            ->whereHas('funnel', static fn ($q) => $q->withoutGlobalScope('tenant')->where('company_id', $company->id))
            ->count();
        $scenarios = FunnelAiScenario::query()->withoutGlobalScope('tenant')->where('company_id', $company->id)->where('enabled', true)->count();
        $knowledge = KnowledgeRule::query()->withoutGlobalScope('tenant')->where('company_id', $company->id)->count();
        $departments = Department::query()->withoutGlobalScope('tenant')->where('company_id', $company->id)->count();

        if ($funnels < 1) {
            $failures[] = 'funnels=0';
        }
        if ($stages < 5) {
            $failures[] = "stages={$stages}";
        }
        if ($scenarios < 1) {
            $failures[] = 'scenarios=0';
        }
        if ($knowledge < 3) {
            $failures[] = "knowledge={$knowledge}";
        }
        if ($departments < 1) {
            $failures[] = 'departments=0';
        }

        $teamKey = config('permission.column_names.team_foreign_key');
        $adminRole = Role::query()
            ->where('name', 'administrator')
            ->where('guard_name', 'web')
            ->where($teamKey, $company->id)
            ->first();

        if ($adminRole === null || $adminRole->permissions()->count() === 0) {
            $failures[] = 'admin_role_permissions_missing';
        }

        $host = strtolower($company->slug).'.'.config('tenancy.root_domain', 'accel.kz');
        $mapPath = $nginxMap->mapFilePath();
        $inMap = is_file($mapPath) && str_contains((string) file_get_contents($mapPath), $host);
        if (! $inMap && ! in_array($host, $nginxMap->knownTenantHosts(), true)) {
            $failures[] = 'nginx_map_missing';
        }

        $passed = $failures === [];
        $result = [
            'status' => $passed ? 'pass' : 'fail',
            'failures' => $failures,
            'checked_at' => now()->toIso8601String(),
            'company_id' => $company->id,
            'slug' => $company->slug,
        ];

        Cache::put("tenant_provision_verify:{$company->id}", $result, now()->addDay());

        if ($passed) {
            Log::info('VerifyTenantProvisioningJob passed', $result);

            return;
        }

        Log::error('VerifyTenantProvisioningJob failed', $result);

        if ($telegram->configured()) {
            $text = '<b>Tenant provisioning failed</b>'."\n"
                .e($company->slug).' #'.$company->id."\n"
                .implode(', ', $failures);
            $telegram->send($text);
        }
    }
}
