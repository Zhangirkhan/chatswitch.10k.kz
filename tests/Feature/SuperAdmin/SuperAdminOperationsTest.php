<?php

declare(strict_types=1);

namespace Tests\Feature\SuperAdmin;

use App\Models\Company;
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
            ->has('auditLogs.data', 1)
            ->where('auditLogs.data.0.action', 'impersonation.start'));
    }
}
