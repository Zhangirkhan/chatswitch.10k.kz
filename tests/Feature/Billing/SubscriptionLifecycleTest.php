<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Models\Company;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\Billing\SubscriptionLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SubscriptionLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function standardPlan(): Plan
    {
        return Plan::query()->firstOrCreate(
            ['code' => 'standard'],
            [
                'name' => 'Стандарт',
                'price_cents' => 4_000_000,
                'currency' => 'KZT',
                'interval' => 'month',
                'trial_days' => 14,
                'is_active' => true,
            ],
        );
    }

    public function test_start_trial_creates_history_and_sets_company(): void
    {
        $plan = $this->standardPlan();

        $company = Company::query()->create([
            'name' => 'Test Co',
            'slug' => 'test-co',
            'is_active' => true,
        ]);

        $service = app(SubscriptionLifecycleService::class);
        $subscription = $service->startTrial($company, $plan);

        $company->refresh();

        $this->assertSame('trial', $company->subscription_status);
        $this->assertNotNull($company->trial_ends_at);
        $this->assertSame($plan->id, $company->plan_id);
        $this->assertSame('trial', $subscription->status);
        $this->assertSame('trial_started', $subscription->event);
        $this->assertSame(1, Subscription::query()->where('company_id', $company->id)->count());
    }

    public function test_activate_paid_closes_trial_and_starts_active_period(): void
    {
        $plan = $this->standardPlan();

        $company = Company::query()->create([
            'name' => 'Pay Co',
            'slug' => 'pay-co',
            'is_active' => true,
            'plan_id' => $plan->id,
            'subscription_status' => 'trial',
        ]);

        $service = app(SubscriptionLifecycleService::class);
        $service->startTrial($company, $plan);
        $service->activatePaid($company->fresh(), $plan);

        $company->refresh();
        $history = Subscription::query()->where('company_id', $company->id)->orderBy('id')->get();

        $this->assertSame('active', $company->subscription_status);
        $this->assertNotNull($company->current_period_ends_at);
        $this->assertCount(2, $history);
        $this->assertNotNull($history[0]->ended_at);
        $this->assertSame('active', $history[1]->status);
        $this->assertSame('activated', $history[1]->event);
    }

    public function test_apply_payment_activates_past_due_company(): void
    {
        $plan = $this->standardPlan();

        $company = Company::query()->create([
            'name' => 'Due Co',
            'slug' => 'due-co',
            'is_active' => true,
            'plan_id' => $plan->id,
            'subscription_status' => 'past_due',
        ]);

        $service = app(SubscriptionLifecycleService::class);
        $result = $service->applyPaymentToSubscription($company, $plan->price_cents);

        $this->assertNotNull($result);
        $company->refresh();

        $this->assertSame('active', $company->subscription_status);
        $this->assertNotNull($company->current_period_ends_at);
        $this->assertSame(1, $result['months']);
        $this->assertSame('activated', $result['subscription']->event);
    }

    public function test_prepare_company_moves_expired_trial_to_past_due_before_payment(): void
    {
        $plan = $this->standardPlan();

        $company = Company::query()->create([
            'name' => 'Expired Trial',
            'slug' => 'expired-trial',
            'is_active' => true,
            'plan_id' => $plan->id,
            'subscription_status' => 'trial',
            'trial_ends_at' => now()->subDay(),
        ]);

        $service = app(SubscriptionLifecycleService::class);
        $service->startTrial($company, $plan);
        $company->update(['trial_ends_at' => now()->subDay()]);
        $prepared = $service->prepareCompanyForPaidActivation($company->fresh());

        $this->assertSame('past_due', $prepared->subscription_status);
    }

    public function test_apply_payment_skips_canceled_company(): void
    {
        $plan = $this->standardPlan();

        $company = Company::query()->create([
            'name' => 'Canceled Co',
            'slug' => 'canceled-co',
            'is_active' => true,
            'plan_id' => $plan->id,
            'subscription_status' => 'canceled',
        ]);

        $service = app(SubscriptionLifecycleService::class);
        $result = $service->applyPaymentToSubscription($company, $plan->price_cents);

        $this->assertNull($result);
        $this->assertSame('canceled', $company->fresh()->subscription_status);
    }

    public function test_cancel_preserves_history(): void
    {
        $plan = $this->standardPlan();

        $company = Company::query()->create([
            'name' => 'Cancel Co',
            'slug' => 'cancel-co',
            'is_active' => true,
            'plan_id' => $plan->id,
        ]);

        $service = app(SubscriptionLifecycleService::class);
        $service->startTrial($company, $plan);
        $service->cancel($company->fresh());

        $company->refresh();

        $this->assertSame('canceled', $company->subscription_status);
        $this->assertSame(1, Subscription::query()->where('company_id', $company->id)->count());
        $this->assertNotNull(Subscription::query()->first()?->canceled_at);
    }
}
