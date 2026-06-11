<?php

declare(strict_types=1);

namespace Tests\Feature\SuperAdmin;

use App\Models\Company;
use App\Models\SuperAdminAuditLog;
use App\Models\User;
use App\Services\Tenancy\TenantDoctorService;
use App\Support\TenantRoles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class CompanyOwnerTest extends TestCase
{
    use RefreshDatabase;

    private function adminHost(): string
    {
        return config('tenancy.admin_subdomain', 'app').'.'.config('tenancy.root_domain', 'accel.kz');
    }

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('administrator', 'web');
        Role::findOrCreate('manager', 'web');
    }

    public function test_super_admin_can_assign_owner_via_patch(): void
    {
        $adminHost = $this->adminHost();

        $company = Company::query()->create([
            'name' => 'Owner Patch Co',
            'slug' => 'owner-patch-co',
            'is_active' => true,
            'subscription_status' => 'active',
        ]);

        TenantRoles::ensureDefaultRolesForCompany($company);

        $adminUser = User::factory()->create([
            'company_id' => $company->id,
            'is_active' => true,
            'email' => 'admin@owner-patch-co.kz',
        ]);
        TenantRoles::assign($adminUser, 'administrator');

        $super = User::factory()->create([
            'is_super_admin' => true,
            'company_id' => null,
        ]);

        $this->withServerVariables(['HTTP_HOST' => $adminHost]);
        URL::forceRootUrl('http://'.$adminHost);

        $response = $this->actingAs($super)->patch(
            "http://{$adminHost}/companies/{$company->id}/owner",
            ['user_id' => $adminUser->id],
        );

        $response->assertRedirect();
        $this->assertSame($adminUser->id, $company->fresh()->owner_user_id);

        $this->assertDatabaseHas('super_admin_audit_logs', [
            'company_id' => $company->id,
            'actor_user_id' => $super->id,
            'action' => 'company.owner_assigned',
            'subject_id' => $adminUser->id,
        ]);
    }

    public function test_patch_owner_rejects_non_administrator(): void
    {
        $adminHost = $this->adminHost();

        $company = Company::query()->create([
            'name' => 'Reject Owner Co',
            'slug' => 'reject-owner-co',
            'is_active' => true,
            'subscription_status' => 'active',
        ]);

        TenantRoles::ensureDefaultRolesForCompany($company);

        $manager = User::factory()->create([
            'company_id' => $company->id,
            'is_active' => true,
            'email' => 'manager@reject-owner-co.kz',
        ]);
        TenantRoles::assign($manager, 'manager');

        $super = User::factory()->create([
            'is_super_admin' => true,
            'company_id' => null,
        ]);

        $this->withServerVariables(['HTTP_HOST' => $adminHost]);
        URL::forceRootUrl('http://'.$adminHost);

        $response = $this->actingAs($super)->patch(
            "http://{$adminHost}/companies/{$company->id}/owner",
            ['user_id' => $manager->id],
        );

        $response->assertSessionHasErrors('user_id');
        $this->assertNull($company->fresh()->owner_user_id);
    }

    public function test_sandbox_super_admin_cannot_assign_owner_for_foreign_company(): void
    {
        $adminHost = $this->adminHost();

        $otherProvisioner = User::factory()->create([
            'is_super_admin' => true,
            'company_id' => null,
            'super_admin_scope' => 'sandbox',
        ]);

        $company = Company::query()->create([
            'name' => 'Foreign Co',
            'slug' => 'foreign-co',
            'is_active' => true,
            'subscription_status' => 'active',
            'provisioned_by_user_id' => $otherProvisioner->id,
        ]);

        TenantRoles::ensureDefaultRolesForCompany($company);

        $adminUser = User::factory()->create([
            'company_id' => $company->id,
            'is_active' => true,
        ]);
        TenantRoles::assign($adminUser, 'administrator');

        $sandboxSuper = User::factory()->create([
            'is_super_admin' => true,
            'company_id' => null,
            'super_admin_scope' => 'sandbox',
        ]);

        $this->withServerVariables(['HTTP_HOST' => $adminHost]);
        URL::forceRootUrl('http://'.$adminHost);

        $response = $this->actingAs($sandboxSuper)->patch(
            "http://{$adminHost}/companies/{$company->id}/owner",
            ['user_id' => $adminUser->id],
        );

        $response->assertForbidden();
        $this->assertNull($company->fresh()->owner_user_id);
        $this->assertSame(0, SuperAdminAuditLog::query()->where('action', 'company.owner_assigned')->count());
    }

    public function test_tenant_doctor_passes_owner_checks_after_assignment(): void
    {
        $company = Company::query()->create([
            'name' => 'Doctor Owner Co',
            'slug' => 'doctor-owner-co',
            'is_active' => true,
            'subscription_status' => 'active',
        ]);

        TenantRoles::ensureDefaultRolesForCompany($company);

        $adminUser = User::factory()->create([
            'company_id' => $company->id,
            'is_active' => true,
            'email' => 'admin@doctor-owner-co.kz',
        ]);
        TenantRoles::assign($adminUser, 'administrator');

        app(\App\Services\SuperAdmin\CompanyOwnerService::class)->assign($company, $adminUser);

        $doctor = app(TenantDoctorService::class);
        $report = $doctor->diagnose($company->fresh(['owner']), includeInfra: false);

        $dataChecks = collect($report['groups']['data']['checks'] ?? []);
        $permissionChecks = collect($report['groups']['permissions']['checks'] ?? []);

        $this->assertTrue($dataChecks->firstWhere('key', 'owner')['ok'] ?? false);
        $this->assertTrue($permissionChecks->firstWhere('key', 'owner_admin')['ok'] ?? false);
    }
}
