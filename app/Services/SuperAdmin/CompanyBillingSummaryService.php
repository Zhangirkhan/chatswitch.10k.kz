<?php

declare(strict_types=1);

namespace App\Services\SuperAdmin;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\Payment;
use Carbon\Carbon;

final class CompanyBillingSummaryService
{
    /**
     * @return array{
     *     mrr_kzt: float,
     *     next_payment_at: string|null,
     *     overdue_invoices: int,
     *     trial_days_left: int|null,
     *     revenue_sparkline: list<array{label: string, amount_kzt: float}>
     * }
     */
    public function forCompany(Company $company): array
    {
        $company->loadMissing('plan');

        $mrrKzt = 0.0;
        if ($company->subscription_status === 'active' && $company->plan !== null) {
            $mrrKzt = round($company->plan->price_cents / 100, 2);
        }

        $trialDaysLeft = null;
        if ($company->subscription_status === 'trial' && $company->trial_ends_at !== null) {
            $trialDaysLeft = max(0, (int) now()->diffInDays($company->trial_ends_at, false));
        }

        $overdue = Invoice::query()
            ->where('company_id', $company->id)
            ->where('status', 'issued')
            ->count();

        return [
            'mrr_kzt' => $mrrKzt,
            'next_payment_at' => $company->current_period_ends_at?->toIso8601String(),
            'overdue_invoices' => $overdue,
            'trial_days_left' => $trialDaysLeft,
            'revenue_sparkline' => $this->revenueSparkline($company->id),
        ];
    }

    /**
     * @return list<array{label: string, amount_kzt: float}>
     */
    private function revenueSparkline(int $companyId): array
    {
        $months = collect(range(5, 0))->map(fn (int $i) => now()->subMonths($i)->format('Y-m'));

        $totals = Payment::query()
            ->where('company_id', $companyId)
            ->where('paid_at', '>=', now()->subMonths(6)->startOfMonth())
            ->get(['paid_at', 'amount_cents'])
            ->groupBy(fn (Payment $p) => Carbon::parse($p->paid_at)->format('Y-m'))
            ->map(fn ($group) => (int) $group->sum('amount_cents'));

        return $months->map(function (string $month) use ($totals): array {
            $cents = (int) ($totals[$month] ?? 0);

            return [
                'label' => Carbon::createFromFormat('Y-m', $month)->locale('ru')->translatedFormat('M'),
                'amount_kzt' => round($cents / 100, 2),
            ];
        })->values()->all();
    }
}
