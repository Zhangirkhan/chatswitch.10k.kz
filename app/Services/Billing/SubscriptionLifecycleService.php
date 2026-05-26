<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\Company;
use App\Models\Plan;
use App\Models\Subscription;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

final class SubscriptionLifecycleService
{
    public function defaultPlan(): Plan
    {
        $code = (string) config('billing.default_plan_code', 'standard');

        $plan = Plan::query()
            ->where('code', $code)
            ->where('is_active', true)
            ->first();

        if ($plan !== null) {
            return $plan;
        }

        $plan = Plan::query()->where('is_active', true)->orderBy('id')->first();

        if ($plan === null) {
            throw new \RuntimeException('Нет активного тарифа в системе.');
        }

        return $plan;
    }

    public function trialDaysFor(Plan $plan): int
    {
        return max(1, (int) ($plan->trial_days ?: config('billing.trial_days', 14)));
    }

    /**
     * Старт триала: новая запись в истории подписок + поля компании.
     */
    public function startTrial(Company $company, ?Plan $plan = null): Subscription
    {
        $plan ??= $this->defaultPlan();
        $trialEnds = now()->addDays($this->trialDaysFor($plan));

        return DB::transaction(function () use ($company, $plan, $trialEnds): Subscription {
            $this->closeOpenSubscriptions($company, 'trial_started');

            $subscription = Subscription::query()->create([
                'company_id' => $company->id,
                'plan_id' => $plan->id,
                'status' => 'trial',
                'event' => 'trial_started',
                'started_at' => now(),
                'trial_ends_at' => $trialEnds,
                'ends_at' => $trialEnds,
            ]);

            $company->update([
                'plan_id' => $plan->id,
                'subscription_status' => 'trial',
                'trial_ends_at' => $trialEnds,
                'current_period_ends_at' => null,
            ]);

            return $subscription;
        });
    }

    /**
     * Оплата после триала (или продление): активная подписка на 1 месяц.
     */
    public function activatePaid(Company $company, ?Plan $plan = null, ?CarbonInterface $periodEnds = null): Subscription
    {
        $plan ??= $company->plan ?? $this->defaultPlan();
        $periodEnds ??= now()->addMonth();

        return DB::transaction(function () use ($company, $plan, $periodEnds): Subscription {
            $this->closeOpenSubscriptions($company, 'activated');

            $subscription = Subscription::query()->create([
                'company_id' => $company->id,
                'plan_id' => $plan->id,
                'status' => 'active',
                'event' => 'activated',
                'started_at' => now(),
                'ends_at' => $periodEnds,
                'trial_ends_at' => null,
            ]);

            $company->update([
                'plan_id' => $plan->id,
                'subscription_status' => 'active',
                'trial_ends_at' => null,
                'current_period_ends_at' => $periodEnds,
            ]);

            return $subscription;
        });
    }

    /**
     * Отказ от подписки — история сохраняется, доступ можно закрыть через is_active.
     */
    public function cancel(Company $company): Subscription
    {
        return DB::transaction(function () use ($company): Subscription {
            $open = $this->findOpenSubscription($company);

            if ($open !== null) {
                $open->update([
                    'status' => 'canceled',
                    'event' => 'canceled',
                    'canceled_at' => now(),
                    'ended_at' => now(),
                ]);
                $subscription = $open->fresh();
            } else {
                $subscription = Subscription::query()->create([
                    'company_id' => $company->id,
                    'plan_id' => $company->plan_id ?? $this->defaultPlan()->id,
                    'status' => 'canceled',
                    'event' => 'canceled',
                    'started_at' => now(),
                    'canceled_at' => now(),
                    'ended_at' => now(),
                ]);
            }

            $company->update([
                'subscription_status' => 'canceled',
                'current_period_ends_at' => null,
            ]);

            return $subscription;
        });
    }

    /**
     * Смена тарифа — закрывает текущую подписку и открывает новую (триал или активная).
     */
    public function changePlan(Company $company, Plan $plan, bool $restartTrial = false): Subscription
    {
        if ($restartTrial) {
            return $this->startTrial($company->fresh(), $plan);
        }

        if ($company->subscription_status === 'active') {
            return $this->activatePaid($company->fresh(), $plan);
        }

        return $this->startTrial($company->fresh(), $plan);
    }

    /**
     * После оплаты счёта: активировать или продлить подписку (триал, просрочка, продление).
     *
     * @return array{subscription: Subscription, months: int, period_ends: CarbonInterface}|null
     */
    public function applyPaymentToSubscription(Company $company, int $amountCents): ?array
    {
        $company = $this->prepareCompanyForPaidActivation($company);

        if (! in_array($company->subscription_status, ['trial', 'past_due', 'active'], true)) {
            return null;
        }

        $plan = $company->plan ?? $this->defaultPlan();
        $months = $this->monthsForPaymentAmount($plan, $amountCents);
        $periodEnds = $this->periodEndsAfterPayment($company, $months);
        $subscription = $this->activatePaid($company->fresh(), $plan, $periodEnds);

        return [
            'subscription' => $subscription,
            'months' => $months,
            'period_ends' => $periodEnds,
        ];
    }

    /**
     * Истёкшие триалы → past_due (ожидание оплаты или отказа).
     *
     * @return int количество обновлённых компаний
     */
    public function expireEndedTrials(): int
    {
        $count = 0;

        Company::query()
            ->where('subscription_status', 'trial')
            ->whereNotNull('trial_ends_at')
            ->where('trial_ends_at', '<', now())
            ->each(function (Company $company) use (&$count): void {
                DB::transaction(function () use ($company, &$count): void {
                    $open = $this->findOpenSubscription($company);
                    if ($open !== null && $open->status === 'trial') {
                        $open->update([
                            'status' => 'past_due',
                            'event' => 'trial_expired',
                            'ended_at' => now(),
                        ]);
                    }

                    $company->update(['subscription_status' => 'past_due']);
                    $count++;
                });
            });

        return $count;
    }

    /**
     * Перед оплатой: истёкший триал → past_due (как в subscriptions/activate после триала).
     */
    public function prepareCompanyForPaidActivation(Company $company): Company
    {
        if (
            $company->subscription_status === 'trial'
            && $company->trial_ends_at !== null
            && Carbon::parse($company->trial_ends_at)->isPast()
        ) {
            DB::transaction(function () use ($company): void {
                $open = $this->findOpenSubscription($company);
                if ($open !== null && $open->status === 'trial') {
                    $open->update([
                        'status' => 'past_due',
                        'event' => 'trial_expired',
                        'ended_at' => now(),
                    ]);
                }
                $company->update(['subscription_status' => 'past_due']);
            });
        }

        return $company->fresh(['plan']) ?? $company;
    }

    private function monthsForPaymentAmount(Plan $plan, int $amountCents): int
    {
        if ($plan->price_cents < 1) {
            return 1;
        }

        return max(1, (int) round($amountCents / $plan->price_cents));
    }

    private function periodEndsAfterPayment(Company $company, int $months): CarbonInterface
    {
        $base = $company->current_period_ends_at !== null
            && Carbon::parse($company->current_period_ends_at)->isFuture()
            ? Carbon::parse($company->current_period_ends_at)
            : now();

        return $base->copy()->addMonths($months);
    }

    private function closeOpenSubscriptions(Company $company, string $event): void
    {
        Subscription::query()
            ->where('company_id', $company->id)
            ->whereIn('status', ['trial', 'active', 'past_due'])
            ->whereNull('ended_at')
            ->each(function (Subscription $subscription) use ($event): void {
                $subscription->update([
                    'ended_at' => now(),
                    'event' => $event,
                ]);
            });
    }

    private function findOpenSubscription(Company $company): ?Subscription
    {
        return Subscription::query()
            ->where('company_id', $company->id)
            ->whereIn('status', ['trial', 'active', 'past_due'])
            ->whereNull('ended_at')
            ->orderByDesc('id')
            ->first();
    }
}
