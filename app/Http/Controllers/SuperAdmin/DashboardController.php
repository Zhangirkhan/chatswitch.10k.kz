<?php

declare(strict_types=1);

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\TenantSignupRequest;
use App\Services\Feedback\UserFeedbackPopularService;
use App\Services\SuperAdmin\SuperAdminCompanyScope;
use App\Services\SuperAdmin\TenantDeviceStatsService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class DashboardController extends Controller
{
    public function __construct(
        private readonly SuperAdminCompanyScope $superAdminScope,
        private readonly UserFeedbackPopularService $feedbackPopularService,
        private readonly TenantDeviceStatsService $deviceStats,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $companyQuery = $this->superAdminScope->applyToCompaniesQuery(Company::query(), $user);
        $isSandbox = $this->superAdminScope->isSandboxSuperAdmin($user);

        $activeCompanies = (clone $companyQuery)->where('is_active', true)->count();
        $inactiveCompanies = (clone $companyQuery)->where('is_active', false)->count();
        $pendingSignups = $isSandbox
            ? 0
            : TenantSignupRequest::query()->where('status', 'pending')->count();

        $overdueCutoff = now()->subDays((int) config('billing.invoice_overdue_days', 7));
        $invoiceQuery = Invoice::query()->where('status', 'issued');
        if ($isSandbox && $user !== null) {
            $invoiceQuery->whereIn('company_id', (clone $companyQuery)->pluck('id'));
        }
        $overdueInvoices = (clone $invoiceQuery)->where('issued_at', '<', $overdueCutoff)->count();
        $issuedInvoices = (clone $invoiceQuery)->count();

        $mrrCents = (int) (clone $companyQuery)
            ->where('companies.is_active', true)
            ->where('companies.subscription_status', 'active')
            ->join('plans', 'plans.id', '=', 'companies.plan_id')
            ->sum('plans.price_cents');

        return Inertia::render('SuperAdmin/Dashboard', [
            'stats' => [
                'active_companies' => $activeCompanies,
                'inactive_companies' => $inactiveCompanies,
                'pending_signups' => $pendingSignups,
                'overdue_invoices' => $overdueInvoices,
                'issued_invoices' => $issuedInvoices,
                'mrr_kzt' => round($mrrCents / 100, 2),
            ],
            'deviceStats' => $this->deviceStats->forPlatform($companyQuery),
            'recentCompanies' => (clone $companyQuery)
                ->with('plan:id,name')
                ->latest()
                ->limit(8)
                ->get(['id', 'name', 'slug', 'subscription_status', 'plan_id', 'created_at']),
            'topFeedback' => $isSandbox
                ? []
                : $this->feedbackPopularService->topForDashboard(10)
                    ->map(static fn ($row): array => [
                        'id' => $row->id,
                        'type' => $row->type->value,
                        'message' => $row->message,
                        'likes_count' => (int) $row->likes_count,
                        'created_at' => $row->created_at?->toIso8601String(),
                    ])
                    ->values()
                    ->all(),
            'isSandboxSuperAdmin' => $isSandbox,
        ]);
    }
}
