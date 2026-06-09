<?php

declare(strict_types=1);

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\SuperAdmin\SuperAdminAuditLogger;
use App\Services\SuperAdmin\SuperAdminCompanyScope;
use App\Services\Tenancy\TenantDoctorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class CompanyDoctorController extends Controller
{
    public function __construct(
        private readonly TenantDoctorService $doctor,
        private readonly SuperAdminAuditLogger $audit,
        private readonly SuperAdminCompanyScope $superAdminScope,
    ) {}

    public function fix(Request $request, Company $company): RedirectResponse
    {
        $this->superAdminScope->ensureCanManage($request->user(), $company);

        $company->load('owner');
        $report = $this->doctor->diagnose($company, includeInfra: false);
        $actions = $this->doctor->fix($company, $report['groups']);
        $report = $this->doctor->diagnose($company, includeInfra: false);

        $this->audit->log($company, $request->user(), 'tenant.doctor_fix', $company, [
            'actions' => $actions,
            'ok' => $report['ok'],
            'groups' => collect($report['groups'])->map(
                static fn (array $group): array => [
                    'ok' => $group['ok'],
                    'failed' => collect($group['checks'] ?? [])
                        ->reject(static fn (array $c): bool => $c['ok'])
                        ->pluck('message')
                        ->values()
                        ->all(),
                ],
            )->all(),
        ]);

        $message = $report['ok']
            ? 'Диагностика пройдена. Применено: '.(implode(', ', $actions) ?: 'ничего не потребовалось')
            : 'Починка выполнена, но остались проблемы: '.(implode(', ', $actions) ?: 'см. отчёт');

        return back()->with($report['ok'] ? 'success' : 'warning', $message);
    }
}
