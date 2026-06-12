<?php

declare(strict_types=1);

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\AI\AiSalesMetricsService;
use App\Services\SuperAdmin\SuperAdminCompanyScope;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class AiSalesDashboardController extends Controller
{
    public function __construct(
        private readonly AiSalesMetricsService $metricsService,
        private readonly SuperAdminCompanyScope $superAdminScope,
    ) {}

    public function index(Request $request): Response
    {
        $period = (string) $request->query('period', '30d');
        [$from, $to] = $this->resolvePeriod($period);

        $companyId = $request->filled('company_id')
            ? $request->integer('company_id')
            : null;

        $user = $request->user();
        abort_unless($user !== null, 403);

        $metrics = $this->metricsService->build($user, $from, $to, $companyId);

        $companies = $this->superAdminScope
            ->applyToCompaniesQuery(Company::query()->where('is_active', true), $user)
            ->orderBy('name')
            ->get(['id', 'name', 'slug'])
            ->map(static fn (Company $company): array => [
                'id' => $company->id,
                'name' => $company->name,
                'slug' => $company->slug,
            ])
            ->values()
            ->all();

        return Inertia::render('SuperAdmin/AiSalesDashboard', [
            'metrics' => $metrics,
            'companies' => $companies,
            'filters' => [
                'period' => $period,
                'company_id' => $companyId,
            ],
        ]);
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function resolvePeriod(string $period): array
    {
        $to = now();

        return match ($period) {
            '7d' => [now()->subDays(7)->startOfDay(), $to],
            '90d' => [now()->subDays(90)->startOfDay(), $to],
            default => [now()->subDays(30)->startOfDay(), $to],
        };
    }
}
