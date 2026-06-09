<?php

declare(strict_types=1);

namespace Tests\Feature\SuperAdmin;

use App\Models\Company;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

final class CompanyDemoMaintenanceTest extends TestCase
{
    use RefreshDatabase;

    private function adminHost(): string
    {
        return config('tenancy.admin_subdomain', 'app').'.'.config('tenancy.root_domain', 'accel.kz');
    }

    public function test_companies_index_shows_demo_separately(): void
    {
        $demoSlug = config('tenancy.fallback_slug', 'demo');

        Company::query()->where('slug', $demoSlug)->update(['name' => 'Demo Tenant']);

        Company::query()->create([
            'name' => 'Client Co',
            'slug' => 'client-co',
            'is_active' => true,
            'subscription_status' => 'trial',
        ]);

        $admin = User::factory()->create(['is_super_admin' => true, 'company_id' => null]);
        $host = $this->adminHost();
        URL::forceRootUrl('https://'.$host);

        $this->actingAs($admin)->get("https://{$host}/companies")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('SuperAdmin/Companies/Index')
                ->where('demoCompany.slug', $demoSlug)
                ->has('companies.data', 1)
                ->where('companies.data.0.slug', 'client-co'));
    }

    public function test_seed_test_data_creates_companies(): void
    {
        Plan::query()->firstOrCreate(
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

        $admin = User::factory()->create(['is_super_admin' => true, 'company_id' => null]);
        $host = $this->adminHost();
        URL::forceRootUrl('https://'.$host);

        $this->actingAs($admin)->post("https://{$host}/companies/seed-test-data")
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('companies', ['slug' => 'kofeynya-utro']);
        $this->assertDatabaseHas('companies', ['slug' => 'glow-studio']);
    }

    public function test_delete_all_except_demo_keeps_demo_tenant(): void
    {
        $demoSlug = config('tenancy.fallback_slug', 'demo');

        Company::query()->create([
            'name' => 'Temp Co',
            'slug' => 'temp-co',
            'is_active' => true,
            'subscription_status' => 'trial',
        ]);

        $admin = User::factory()->create(['is_super_admin' => true, 'company_id' => null]);
        $host = $this->adminHost();
        URL::forceRootUrl('https://'.$host);

        $this->actingAs($admin)->delete("https://{$host}/companies/non-demo")
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('companies', ['slug' => 'temp-co']);
        $this->assertDatabaseHas('companies', ['slug' => $demoSlug]);
    }

    public function test_delete_single_company_removes_tenant_and_users(): void
    {
        $company = Company::query()->create([
            'name' => 'Single Co',
            'slug' => 'single-co',
            'is_active' => true,
            'subscription_status' => 'trial',
        ]);

        User::factory()->create([
            'company_id' => $company->id,
            'is_super_admin' => false,
        ]);

        $admin = User::factory()->create(['is_super_admin' => true, 'company_id' => null]);
        $host = $this->adminHost();
        URL::forceRootUrl('https://'.$host);

        $this->actingAs($admin)->delete("https://{$host}/companies/{$company->id}")
            ->assertRedirect(route('super.companies.index', absolute: false))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('companies', ['slug' => 'single-co']);
        $this->assertDatabaseMissing('users', ['company_id' => $company->id]);
    }

    public function test_delete_demo_company_is_forbidden(): void
    {
        $demoSlug = config('tenancy.fallback_slug', 'demo');

        $demo = Company::query()->where('slug', $demoSlug)->first();
        $this->assertNotNull($demo);

        $admin = User::factory()->create(['is_super_admin' => true, 'company_id' => null]);
        $host = $this->adminHost();
        URL::forceRootUrl('https://'.$host);

        $this->actingAs($admin)->delete("https://{$host}/companies/{$demo->id}")
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertDatabaseHas('companies', ['slug' => $demoSlug]);
    }
}
