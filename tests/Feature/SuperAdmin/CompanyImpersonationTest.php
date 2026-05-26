<?php

declare(strict_types=1);

namespace Tests\Feature\SuperAdmin;

use App\Models\Company;
use App\Models\User;
use App\Services\SuperAdmin\TenantImpersonationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\URL;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class CompanyImpersonationTest extends TestCase
{
    use RefreshDatabase;

    private function adminHost(): string
    {
        return config('tenancy.admin_subdomain', 'app').'.'.config('tenancy.root_domain', 'accel.kz');
    }

    private function tenantHost(string $slug): string
    {
        return $slug.'.'.config('tenancy.root_domain', 'accel.kz');
    }

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('administrator', 'web');
    }

    public function test_super_admin_can_start_impersonation_and_land_in_tenant_settings(): void
    {
        $adminHost = $this->adminHost();
        $slug = 'impersonate-co';

        $company = Company::query()->create([
            'name' => 'Impersonate Co',
            'slug' => $slug,
            'is_active' => true,
            'subscription_status' => 'active',
        ]);

        $owner = User::factory()->create([
            'company_id' => $company->id,
            'is_active' => true,
            'is_super_admin' => false,
        ]);
        $owner->assignRole('administrator');
        $company->update(['owner_user_id' => $owner->id]);

        $super = User::factory()->create([
            'is_super_admin' => true,
            'company_id' => null,
        ]);

        $this->withServerVariables(['HTTP_HOST' => $adminHost]);
        URL::forceRootUrl('http://'.$adminHost);

        $start = $this->actingAs($super)->post("http://{$adminHost}/companies/{$company->id}/impersonate");

        $inertiaStart = $this->actingAs($super)->post(
            "http://{$adminHost}/companies/{$company->id}/impersonate",
            [],
            ['X-Inertia' => 'true'],
        );
        $inertiaStart->assertStatus(409);
        $acceptUrl = $inertiaStart->headers->get('X-Inertia-Location');
        $this->assertNotNull($acceptUrl);

        $start = $this->actingAs($super)->post("http://{$adminHost}/companies/{$company->id}/impersonate");
        $start->assertRedirect();
        $acceptUrl = $start->headers->get('Location');
        $this->assertNotNull($acceptUrl);
        $this->assertStringContainsString($slug.'.', (string) $acceptUrl);
        $this->assertStringContainsString('/impersonate/accept', (string) $acceptUrl);

        $tenantHost = $this->tenantHost($slug);
        $this->withServerVariables(['HTTP_HOST' => $tenantHost]);
        URL::forceRootUrl('http://'.$tenantHost);
        URL::defaults(['tenant' => $slug]);

        $accept = $this->get((string) $acceptUrl);

        $accept->assertRedirect(route('settings.connections', ['tenant' => $slug], absolute: false));
        $this->assertAuthenticatedAs($owner);
        $this->assertEquals(
            $company->name,
            session(TenantImpersonationService::SESSION_KEY.'.company_name'),
        );
    }

    public function test_impersonation_leave_logs_out_tenant_and_redirects_to_super_admin(): void
    {
        $slug = 'leave-co';
        $adminHost = $this->adminHost();
        $tenantHost = $this->tenantHost($slug);

        $company = Company::query()->create([
            'name' => 'Leave Co',
            'slug' => $slug,
            'is_active' => true,
            'subscription_status' => 'active',
        ]);

        $owner = User::factory()->create([
            'company_id' => $company->id,
            'is_active' => true,
        ]);
        $owner->assignRole('administrator');
        $company->update(['owner_user_id' => $owner->id]);

        $returnUrl = "http://{$adminHost}/companies/{$company->id}";

        $this->withServerVariables(['HTTP_HOST' => $tenantHost]);
        URL::forceRootUrl('http://'.$tenantHost);
        URL::defaults(['tenant' => $slug]);

        $this->actingAs($owner);
        session([
            TenantImpersonationService::SESSION_KEY => [
                'super_user_id' => 99,
                'super_user_name' => 'Super',
                'company_name' => $company->name,
                'return_url' => $returnUrl,
            ],
        ]);

        $leave = $this->post("http://{$tenantHost}/impersonate/leave");

        $leave->assertRedirect($returnUrl);
        $this->assertGuest();
        $this->assertNull(session(TenantImpersonationService::SESSION_KEY));
    }

    public function test_impersonation_token_is_one_time(): void
    {
        $slug = 'once-co';
        $tenantHost = $this->tenantHost($slug);

        $company = Company::query()->create([
            'name' => 'Once Co',
            'slug' => $slug,
            'is_active' => true,
            'subscription_status' => 'active',
        ]);

        $owner = User::factory()->create(['company_id' => $company->id, 'is_active' => true]);
        $owner->assignRole('administrator');
        $company->update(['owner_user_id' => $owner->id]);

        $super = User::factory()->create(['is_super_admin' => true, 'company_id' => null]);

        $service = app(TenantImpersonationService::class);
        $url = $service->issueRedirectUrl($company, $super);

        $this->withServerVariables(['HTTP_HOST' => $tenantHost]);
        URL::forceRootUrl('http://'.$tenantHost);
        URL::defaults(['tenant' => $slug]);

        $first = $this->get($url);
        $first->assertRedirect();

        $second = $this->get($url);
        $second->assertForbidden();
    }

    protected function tearDown(): void
    {
        Cache::flush();
        parent::tearDown();
    }
}
