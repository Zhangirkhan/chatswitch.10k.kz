<?php

declare(strict_types=1);

namespace Tests\Feature\SuperAdmin;

use App\Models\Company;
use App\Models\Plan;
use App\Models\TenantSignupRequest;
use App\Models\User;
use App\Services\SuperAdmin\TenantImpersonationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class SuperAdminAuditLoggingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('administrator', 'web');
    }

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

    public function test_manual_company_creation_is_audited(): void
    {
        $plan = $this->standardPlan();
        $admin = User::factory()->create(['is_super_admin' => true, 'company_id' => null]);
        $host = $this->adminHost();
        URL::forceRootUrl('https://'.$host);

        $response = $this->actingAs($admin)->post("https://{$host}/companies", [
            'name' => 'Audit Create Co',
            'slug' => 'audit-create-co',
            'phone' => '+77001234567',
            'plan_id' => $plan->id,
            'owner_name' => 'Owner',
            'owner_email' => 'owner@audit-create.test',
        ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('super_admin_audit_logs', [
            'action' => 'company.created',
            'actor_user_id' => $admin->id,
        ]);
    }

    public function test_company_update_and_subscription_activation_are_audited(): void
    {
        $plan = $this->standardPlan();
        $admin = User::factory()->create(['is_super_admin' => true, 'company_id' => null]);

        $company = Company::query()->create([
            'name' => 'Old Name',
            'slug' => 'audit-update-co',
            'is_active' => true,
            'plan_id' => $plan->id,
            'subscription_status' => 'trial',
        ]);

        $host = $this->adminHost();
        URL::forceRootUrl('https://'.$host);

        $this->actingAs($admin)->put("https://{$host}/companies/{$company->id}", [
            'name' => 'New Name',
            'phone' => '',
            'is_active' => true,
            'subscription_status' => 'trial',
            'plan_id' => $plan->id,
        ])->assertRedirect();

        $this->assertDatabaseHas('super_admin_audit_logs', [
            'company_id' => $company->id,
            'action' => 'company.updated',
            'actor_user_id' => $admin->id,
        ]);

        $this->actingAs($admin)->post("https://{$host}/companies/{$company->id}/subscriptions/activate", [
            'months' => 3,
        ])->assertRedirect();

        $this->assertDatabaseHas('super_admin_audit_logs', [
            'company_id' => $company->id,
            'action' => 'subscription.activated',
            'actor_user_id' => $admin->id,
        ]);
    }

    public function test_signup_request_reject_is_audited(): void
    {
        $admin = User::factory()->create(['is_super_admin' => true, 'company_id' => null]);

        $signup = TenantSignupRequest::query()->create([
            'company_name' => 'Rejected LLC',
            'desired_slug' => 'rejected-llc',
            'contact_name' => 'Contact',
            'email' => 'reject@test.kz',
            'phone' => '+77001112233',
            'status' => 'pending',
        ]);

        $host = $this->adminHost();
        URL::forceRootUrl('https://'.$host);

        $this->actingAs($admin)->post("https://{$host}/signup-requests/{$signup->id}/reject")
            ->assertRedirect();

        $this->assertDatabaseHas('super_admin_audit_logs', [
            'action' => 'signup_request.rejected',
            'actor_user_id' => $admin->id,
            'company_id' => null,
        ]);
    }

    public function test_impersonation_end_is_audited(): void
    {
        $slug = 'imp-audit-co';
        $adminHost = $this->adminHost();
        $tenantHost = $slug.'.'.config('tenancy.root_domain', 'accel.kz');

        $company = Company::query()->create([
            'name' => 'Impersonate Audit Co',
            'slug' => $slug,
            'is_active' => true,
            'subscription_status' => 'active',
        ]);

        $tenantAdmin = User::factory()->create([
            'company_id' => $company->id,
            'is_active' => true,
        ]);
        $tenantAdmin->assignRole('administrator');
        $company->update(['owner_user_id' => $tenantAdmin->id]);

        $admin = User::factory()->create(['is_super_admin' => true, 'company_id' => null]);
        $returnUrl = "http://{$adminHost}/companies/{$company->id}";

        $this->withServerVariables(['HTTP_HOST' => $tenantHost]);
        URL::forceRootUrl('http://'.$tenantHost);
        URL::defaults(['tenant' => $slug]);

        $this->actingAs($tenantAdmin);
        session([
            TenantImpersonationService::SESSION_KEY => [
                'super_user_id' => $admin->id,
                'super_user_name' => $admin->name,
                'company_id' => $company->id,
                'company_name' => $company->name,
                'return_url' => $returnUrl,
            ],
        ]);

        $this->post("http://{$tenantHost}/impersonate/leave")->assertRedirect($returnUrl);

        $this->assertDatabaseHas('super_admin_audit_logs', [
            'company_id' => $company->id,
            'action' => 'impersonation.end',
            'actor_user_id' => $admin->id,
        ]);
    }
}
