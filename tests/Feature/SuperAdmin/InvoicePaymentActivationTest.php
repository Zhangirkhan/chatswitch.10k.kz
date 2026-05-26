<?php

declare(strict_types=1);

namespace Tests\Feature\SuperAdmin;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

final class InvoicePaymentActivationTest extends TestCase
{
    use RefreshDatabase;

    private function adminHost(): string
    {
        return config('tenancy.admin_subdomain', 'app').'.'.config('tenancy.root_domain', 'accel.kz');
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

    public function test_recording_payment_activates_past_due_subscription(): void
    {
        $plan = $this->standardPlan();
        $host = $this->adminHost();

        $company = Company::query()->create([
            'name' => 'Payable Co',
            'slug' => 'payable-co',
            'is_active' => true,
            'plan_id' => $plan->id,
            'subscription_status' => 'past_due',
        ]);

        $invoice = Invoice::query()->create([
            'company_id' => $company->id,
            'number' => 'PAY-001',
            'amount_cents' => $plan->price_cents,
            'currency' => 'KZT',
            'status' => 'issued',
            'issued_at' => now(),
        ]);

        $admin = User::factory()->create([
            'is_super_admin' => true,
            'company_id' => null,
        ]);

        $this->withServerVariables(['HTTP_HOST' => $host]);
        URL::forceRootUrl('https://'.$host);

        $response = $this->actingAs($admin)->post(
            "https://{$host}/invoices/{$invoice->id}/payments",
            [
                'amount_cents' => $plan->price_cents,
                'method' => 'kaspi',
            ],
        );

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $company->refresh();
        $invoice->refresh();

        $this->assertSame('paid', $invoice->status);
        $this->assertSame('active', $company->subscription_status);
        $this->assertNotNull($company->current_period_ends_at);
        $this->assertNotNull($invoice->subscription_id);
    }
}
