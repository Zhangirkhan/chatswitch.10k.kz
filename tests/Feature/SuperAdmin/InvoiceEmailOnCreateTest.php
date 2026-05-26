<?php

declare(strict_types=1);

namespace Tests\Feature\SuperAdmin;

use App\Mail\InvoiceIssuedMail;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\SuperAdminAuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

final class InvoiceEmailOnCreateTest extends TestCase
{
    use RefreshDatabase;

    private function adminHost(): string
    {
        return config('tenancy.admin_subdomain', 'app').'.'.config('tenancy.root_domain', 'accel.kz');
    }

    public function test_invoice_store_sends_email_when_requested(): void
    {
        Mail::fake();

        $plan = Plan::query()->firstOrCreate(
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

        $company = Company::query()->create([
            'name' => 'Mail Co',
            'slug' => 'mail-co',
            'is_active' => true,
            'plan_id' => $plan->id,
            'subscription_status' => 'trial',
            'email' => 'billing@mail-co.test',
        ]);

        $owner = User::factory()->create([
            'company_id' => $company->id,
            'email' => 'owner@mail-co.test',
        ]);
        $company->update(['owner_user_id' => $owner->id]);

        $admin = User::factory()->create([
            'is_super_admin' => true,
            'company_id' => null,
        ]);

        $host = $this->adminHost();
        URL::forceRootUrl('https://'.$host);

        $response = $this->actingAs($admin)->post(
            "https://{$host}/companies/{$company->id}/invoices",
            [
                'number' => 'INV-TEST-001',
                'amount_cents' => $plan->price_cents,
                'currency' => 'KZT',
                'send_email' => true,
            ],
        );

        $response->assertRedirect();
        $response->assertSessionHas('success');

        Mail::assertSent(InvoiceIssuedMail::class, function (InvoiceIssuedMail $mail) use ($owner): bool {
            return $mail->hasTo($owner->email);
        });
    }
}
