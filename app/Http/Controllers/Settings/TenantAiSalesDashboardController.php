<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Services\AI\AiSalesMetricsService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class TenantAiSalesDashboardController extends Controller
{
    public function __construct(
        private readonly AiSalesMetricsService $metricsService,
    ) {}

    public function index(Request $request): Response
    {
        $period = (string) $request->query('period', '30d');
        [$from, $to] = $this->resolvePeriod($period);

        $user = $request->user();
        abort_unless($user !== null, 403);

        $metrics = $this->metricsService->buildForTenant($user, $from, $to);

        return Inertia::render('Settings/AiSalesDashboard', [
            'metrics' => $metrics,
            'filters' => [
                'period' => $period,
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
