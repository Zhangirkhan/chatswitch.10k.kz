<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\Company;
use App\Models\Funnel;
use App\Models\Plan;
use App\Models\User;
use App\Services\Tenancy\CompanyProvisioningService;
use App\Services\Tenancy\TenantDoctorService;
use App\Support\TenantRoles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class TenantsDoctorCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['administrator', 'manager', 'employee'] as $roleName) {
            Role::findOrCreate($roleName, 'web');
        }

        Plan::query()->firstOrCreate(
            ['code' => 'standard'],
            [
                'name' => 'Standard',
                'price_cents' => 4_000_000,
                'currency' => 'KZT',
                'interval' => 'month',
                'trial_days' => 14,
                'is_active' => true,
            ],
        );
    }

    public function test_doctor_reports_broken_tenant_and_fix_repairs_data(): void
    {
        Queue::fake();

        $result = app(CompanyProvisioningService::class)->create([
            'name' => 'Broken Tenant',
            'slug' => 'broken-doc',
            'phone' => '+77001112233',
            'owner_name' => 'Owner',
            'owner_email' => 'owner@broken-doc.kz',
        ]);

        $company = $result['company'];
        Funnel::query()->withoutGlobalScope('tenant')->where('company_id', $company->id)->delete();

        $this->switchTenant($company);

        $this->artisan('tenants:doctor', ['tenant' => 'broken-doc', '--no-infra' => true])
            ->assertFailed();

        $doctor = app(TenantDoctorService::class);
        $report = $doctor->diagnose($company->fresh(['owner']), includeInfra: false);
        $doctor->fix($company, $report['groups']);

        $report = $doctor->diagnose($company->fresh(['owner']), includeInfra: false);
        $this->assertFalse(
            $doctor->hasCriticalFailures($report),
            json_encode($report['groups'], JSON_UNESCAPED_UNICODE),
        );

        $this->assertGreaterThanOrEqual(
            1,
            Funnel::query()->withoutGlobalScope('tenant')->where('company_id', $company->id)->count(),
        );
    }

    public function test_doctor_fix_assigns_owner_when_missing(): void
    {
        $company = Company::query()->create([
            'name' => 'No Owner Doctor',
            'slug' => 'no-owner-doctor',
            'is_active' => true,
            'subscription_status' => 'active',
        ]);

        TenantRoles::ensureDefaultRolesForCompany($company);

        $admin = User::factory()->create([
            'company_id' => $company->id,
            'is_active' => true,
            'email' => 'admin@no-owner-doctor.kz',
        ]);
        TenantRoles::assign($admin, 'administrator');

        $doctor = app(TenantDoctorService::class);
        $report = $doctor->diagnose($company, includeInfra: false);

        $ownerCheck = collect($report['groups']['data']['checks'] ?? [])->firstWhere('key', 'owner');
        $this->assertFalse($ownerCheck['ok'] ?? true);

        $doctor->fix($company, $report['groups']);

        $company->refresh();
        $this->assertSame($admin->id, $company->owner_user_id);

        $report = $doctor->diagnose($company->fresh(['owner']), includeInfra: false);
        $this->assertFalse($doctor->hasCriticalFailures($report));
    }

    public function test_doctor_passes_for_healthy_provisioned_tenant(): void
    {
        Queue::fake();

        app(CompanyProvisioningService::class)->create([
            'name' => 'Healthy Tenant',
            'slug' => 'healthy-doc',
            'phone' => '+77002223344',
            'owner_name' => 'Owner',
            'owner_email' => 'owner@healthy-doc.kz',
        ]);

        $this->artisan('tenants:doctor', ['tenant' => 'healthy-doc', '--no-infra' => true])
            ->assertSuccessful();
    }
}
