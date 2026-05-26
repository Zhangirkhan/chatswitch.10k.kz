<?php

declare(strict_types=1);

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\TenantSignupRequest;
use Inertia\Inertia;
use Inertia\Response;

final class DashboardController extends Controller
{
    public function index(): Response
    {
        $activeCompanies = Company::query()->where('is_active', true)->count();
        $inactiveCompanies = Company::query()->where('is_active', false)->count();
        $pendingSignups = TenantSignupRequest::query()->where('status', 'pending')->count();

        $overdueCutoff = now()->subDays((int) config('billing.invoice_overdue_days', 7));
        $overdueInvoices = Invoice::query()
            ->where('status', 'issued')
            ->where('issued_at', '<', $overdueCutoff)
            ->count();

        $issuedInvoices = Invoice::query()->where('status', 'issued')->count();

        $mrrCents = (int) Company::query()
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
            'recentCompanies' => Company::query()
                ->with('plan:id,name')
                ->latest()
                ->limit(8)
                ->get(['id', 'name', 'slug', 'subscription_status', 'plan_id', 'created_at']),
        ]);
    }
}
