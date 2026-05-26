<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Mail\PaymentReminderMail;
use App\Models\BillingReminderLog;
use App\Models\Company;
use App\Models\Plan;
use App\Models\User;
use App\Services\Billing\PaymentReminderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

final class PaymentReminderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-05-26 09:00:00');
        $host = config('tenancy.admin_subdomain', 'app').'.'.config('tenancy.root_domain', 'accel.kz');
        URL::forceRootUrl('https://'.$host);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

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

    public function test_sends_trial_reminder_seven_days_before_end(): void
    {
        Mail::fake();

        $plan = $this->standardPlan();
        $company = Company::query()->create([
            'name' => 'Trial Co',
            'slug' => 'trial-co',
            'is_active' => true,
            'plan_id' => $plan->id,
            'subscription_status' => 'trial',
            'trial_ends_at' => '2026-06-02 23:59:59',
        ]);

        $owner = User::factory()->create([
            'company_id' => $company->id,
            'email' => 'owner@trial-co.test',
        ]);
        $company->update(['owner_user_id' => $owner->id]);
        $company->refresh();

        $result = app(PaymentReminderService::class)->sendDueReminders(now()->startOfDay());

        $this->assertSame(1, $result['sent']);
        Mail::assertSent(PaymentReminderMail::class, function (PaymentReminderMail $mail) use ($owner): bool {
            return $mail->hasTo($owner->email)
                && $mail->kind === BillingReminderLog::KIND_TRIAL_ENDING
                && $mail->daysBefore === 7;
        });

        $this->assertDatabaseHas('billing_reminder_logs', [
            'company_id' => $company->id,
            'kind' => BillingReminderLog::KIND_TRIAL_ENDING,
            'days_before' => 7,
            'recipient' => $owner->email,
        ]);
    }

    public function test_does_not_send_duplicate_reminder_same_day(): void
    {
        Mail::fake();

        $plan = $this->standardPlan();
        $company = Company::query()->create([
            'name' => 'Trial Co 2',
            'slug' => 'trial-co-2',
            'is_active' => true,
            'plan_id' => $plan->id,
            'subscription_status' => 'trial',
            'trial_ends_at' => '2026-05-29 12:00:00',
        ]);

        $owner = User::factory()->create([
            'company_id' => $company->id,
            'email' => 'owner2@trial-co.test',
        ]);
        $company->update(['owner_user_id' => $owner->id]);

        $service = app(PaymentReminderService::class);
        $today = now()->startOfDay();

        $this->assertSame(1, $service->sendDueReminders($today)['sent']);
        $this->assertSame(0, $service->sendDueReminders($today)['sent']);

        Mail::assertSentCount(1);
    }

    public function test_sends_renewal_reminder_for_active_subscription(): void
    {
        Mail::fake();

        $plan = $this->standardPlan();
        $company = Company::query()->create([
            'name' => 'Active Co',
            'slug' => 'active-co',
            'is_active' => true,
            'plan_id' => $plan->id,
            'subscription_status' => 'active',
            'current_period_ends_at' => '2026-05-27 18:00:00',
        ]);

        $owner = User::factory()->create([
            'company_id' => $company->id,
            'email' => 'billing@active-co.test',
        ]);
        $company->update(['owner_user_id' => $owner->id]);

        $result = app(PaymentReminderService::class)->sendDueReminders(now()->startOfDay());

        $this->assertSame(1, $result['sent']);
        Mail::assertSent(PaymentReminderMail::class, function (PaymentReminderMail $mail): bool {
            return $mail->kind === BillingReminderLog::KIND_PERIOD_RENEWAL
                && $mail->daysBefore === 1;
        });
    }
}
