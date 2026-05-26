<?php

declare(strict_types=1);

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\Billing\SubscriptionLifecycleService;
use App\Services\SuperAdmin\SuperAdminAuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class SubscriptionController extends Controller
{
    public function __construct(
        private readonly SubscriptionLifecycleService $lifecycle,
        private readonly SuperAdminAuditLogger $audit,
    ) {}

    /** Назначить тариф (новая запись в истории). */
    public function store(Request $request, Company $company): RedirectResponse
    {
        $data = $request->validate([
            'plan_id' => ['required', 'integer', 'exists:plans,id'],
            'restart_trial' => ['boolean'],
        ]);

        $plan = Plan::query()->findOrFail((int) $data['plan_id']);
        $this->lifecycle->changePlan(
            $company,
            $plan,
            restartTrial: (bool) ($data['restart_trial'] ?? false),
        );

        $this->audit->log($company, $request->user(), 'subscription.plan_changed', $plan, [
            'plan_id' => $plan->id,
            'plan_name' => $plan->name,
            'restart_trial' => (bool) ($data['restart_trial'] ?? false),
        ]);

        return back()->with('success', 'Тариф назначен, запись добавлена в историю.');
    }

    /** Оплата / активация на месяц после триала. */
    public function activate(Request $request, Company $company): RedirectResponse
    {
        $data = $request->validate([
            'plan_id' => ['nullable', 'integer', 'exists:plans,id'],
            'months' => ['nullable', 'integer', 'min:1', 'max:24'],
        ]);

        $plan = isset($data['plan_id'])
            ? Plan::query()->findOrFail((int) $data['plan_id'])
            : null;

        $months = (int) ($data['months'] ?? 1);

        $this->lifecycle->activatePaid(
            $company,
            $plan,
            now()->addMonths($months),
        );

        $this->audit->log($company, $request->user(), 'subscription.activated', null, [
            'months' => $months,
            'plan_id' => $plan?->id,
            'plan_name' => $plan?->name ?? $company->fresh()?->plan?->name,
        ]);

        return back()->with('success', "Подписка активирована на {$months} мес.");
    }

    /** Отказ от подписки (история сохраняется). */
    public function cancel(Request $request, Company $company): RedirectResponse
    {
        $this->lifecycle->cancel($company);

        $this->audit->log($company, $request->user(), 'subscription.canceled', null, [
            'company_name' => $company->name,
        ]);

        return back()->with('success', 'Подписка отменена. История сохранена.');
    }

    public function update(Request $request, Subscription $subscription): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', 'string', Rule::in(['trial', 'active', 'past_due', 'canceled'])],
            'ends_at' => ['nullable', 'date'],
        ]);

        $previousStatus = $subscription->status;

        $subscription->update([
            'status' => $data['status'],
            'ends_at' => $data['ends_at'] ?? $subscription->ends_at,
        ]);

        $subscription->company?->update(['subscription_status' => $data['status']]);

        $company = $subscription->company;
        if ($company !== null) {
            $this->audit->log($company, $request->user(), 'subscription.record_updated', $subscription, [
                'from' => $previousStatus,
                'to' => $data['status'],
                'subscription_id' => $subscription->id,
            ]);
        }

        return back()->with('success', 'Запись подписки обновлена.');
    }
}
