<?php

declare(strict_types=1);

namespace Tests\Feature\SuperAdmin;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\SuperAdminAuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

final class SuperAdminOperationsTest extends TestCase
{
    use RefreshDatabase;

    private function adminHost(): string
    {
        return config('tenancy.admin_subdomain', 'app').'.'.config('tenancy.root_domain', 'accel.kz');
    }

    public function test_companies_index_supports_search_and_pagination(): void
    {
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

        Company::query()->create([
            'name' => 'Alpha Searchable',
            'slug' => 'alpha-search',
            'is_active' => true,
            'plan_id' => $plan->id,
            'subscription_status' => 'trial',
        ]);

        Company::query()->create([
            'name' => 'Beta Other',
            'slug' => 'beta-other',
            'is_active' => true,
            'plan_id' => $plan->id,
            'subscription_status' => 'active',
        ]);

        $admin = User::factory()->create([
            'is_super_admin' => true,
            'company_id' => null,
        ]);

        $host = $this->adminHost();
        URL::forceRootUrl('https://'.$host);

        $response = $this->actingAs($admin)->get(
            "https://{$host}/companies?q=Alpha",
        );

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('SuperAdmin/Companies/Index')
            ->has('companies.data', 1)
            ->where('companies.data.0.name', 'Alpha Searchable')
            ->has('companies.links'));
    }

    public function test_company_show_loads_tenant_health_without_funnel_stage_company_scope(): void
    {
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
            'name' => 'Health Check Co',
            'slug' => 'health-check-co',
            'is_active' => true,
            'plan_id' => $plan->id,
            'subscription_status' => 'trial',
        ]);

        $admin = User::factory()->create([
            'is_super_admin' => true,
            'company_id' => null,
        ]);

        $host = $this->adminHost();
        URL::forceRootUrl('https://'.$host);

        $this->actingAs($admin)
            ->get("https://{$host}/companies/{$company->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('SuperAdmin/Companies/Show')
                ->has('tenantHealth.groups.data'));
    }

    public function test_dashboard_loads_with_active_subscription_mrr(): void
    {
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

        Company::query()
            ->where('slug', config('tenancy.fallback_slug', 'demo'))
            ->update(['subscription_status' => 'trial', 'plan_id' => null]);

        Company::query()->create([
            'name' => 'MRR Co',
            'slug' => 'mrr-co',
            'is_active' => true,
            'plan_id' => $plan->id,
            'subscription_status' => 'active',
        ]);

        $admin = User::factory()->create([
            'is_super_admin' => true,
            'company_id' => null,
        ]);

        $host = $this->adminHost();
        URL::forceRootUrl('https://'.$host);

        $response = $this->actingAs($admin)->get("https://{$host}/dashboard");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('SuperAdmin/Dashboard')
            ->where('stats.mrr_kzt', 40000));
    }

    public function test_global_audit_logs_page_is_available(): void
    {
        $company = Company::query()->create([
            'name' => 'Audit Co',
            'slug' => 'audit-co',
            'is_active' => true,
            'subscription_status' => 'trial',
        ]);

        $admin = User::factory()->create([
            'is_super_admin' => true,
            'company_id' => null,
        ]);

        SuperAdminAuditLog::query()->create([
            'company_id' => $company->id,
            'actor_user_id' => $admin->id,
            'action' => 'impersonation.start',
            'meta' => ['target_user_email' => 'admin@audit-co.test'],
            'created_at' => now(),
        ]);

        $host = $this->adminHost();
        URL::forceRootUrl('https://'.$host);

        $response = $this->actingAs($admin)->get("https://{$host}/audit-logs");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('SuperAdmin/AuditLogs/Index')
            ->where('tab', 'actions')
            ->has('auditLogs.data', 1)
            ->where('auditLogs.data.0.action', 'impersonation.start'));
    }

    public function test_global_journal_transactions_tab_lists_payments_from_all_companies(): void
    {
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

        $companyA = Company::query()->create([
            'name' => 'Pay Co A',
            'slug' => 'pay-co-a',
            'is_active' => true,
            'plan_id' => $plan->id,
            'subscription_status' => 'active',
        ]);

        $companyB = Company::query()->create([
            'name' => 'Pay Co B',
            'slug' => 'pay-co-b',
            'is_active' => true,
            'plan_id' => $plan->id,
            'subscription_status' => 'active',
        ]);

        $admin = User::factory()->create([
            'is_super_admin' => true,
            'company_id' => null,
        ]);

        $invoiceA = Invoice::query()->create([
            'company_id' => $companyA->id,
            'number' => 'TX-A-001',
            'amount_cents' => 4_000_000,
            'currency' => 'KZT',
            'status' => 'paid',
            'issued_at' => now(),
            'paid_at' => now(),
        ]);

        $invoiceB = Invoice::query()->create([
            'company_id' => $companyB->id,
            'number' => 'TX-B-001',
            'amount_cents' => 4_000_000,
            'currency' => 'KZT',
            'status' => 'paid',
            'issued_at' => now(),
            'paid_at' => now(),
        ]);

        Payment::query()->create([
            'invoice_id' => $invoiceA->id,
            'company_id' => $companyA->id,
            'amount_cents' => 4_000_000,
            'method' => 'kaspi',
            'paid_at' => now()->subDay(),
            'recorded_by_user_id' => $admin->id,
        ]);

        Payment::query()->create([
            'invoice_id' => $invoiceB->id,
            'company_id' => $companyB->id,
            'amount_cents' => 4_000_000,
            'method' => 'bank_transfer',
            'paid_at' => now(),
            'recorded_by_user_id' => $admin->id,
        ]);

        $host = $this->adminHost();
        URL::forceRootUrl('https://'.$host);

        $response = $this->actingAs($admin)->get("https://{$host}/audit-logs?tab=transactions");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('SuperAdmin/AuditLogs/Index')
            ->where('tab', 'transactions')
            ->has('transactions.data', 2)
            ->where('transactions.data.0.company.name', 'Pay Co B')
            ->where('transactions.data.1.company.name', 'Pay Co A'));
    }
}
