<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Models\Funnel;
use App\Models\FunnelStage;
use App\Models\Plan;
use App\Models\User;
use App\Services\Tenancy\CompanyProvisioningService;
use App\Support\TenantRoles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class CompanyProvisioningServiceTest extends TestCase
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

    public function test_create_provisions_funnels_roles_and_owner_department(): void
    {
        Queue::fake();

        $service = app(CompanyProvisioningService::class);

        $result = $service->create([
            'name' => 'New Tenant',
            'slug' => 'new-tenant-prov',
            'phone' => '+77001234567',
            'owner_name' => 'Owner User',
            'owner_email' => 'owner@new-tenant.kz',
        ]);

        $company = $result['company'];
        $owner = $result['owner'];

        $this->assertSame('new-tenant-prov', $company->slug);
        $this->assertNotNull($owner->department_id);

        $funnels = Funnel::query()->withoutGlobalScope('tenant')->where('company_id', $company->id)->count();
        $this->assertGreaterThanOrEqual(1, $funnels);

        $stages = FunnelStage::query()
            ->whereHas('funnel', static fn ($q) => $q->withoutGlobalScope('tenant')->where('company_id', $company->id))
            ->count();
        $this->assertGreaterThanOrEqual(5, $stages);

        TenantRoles::ensureDefaultRolesForCompany($company);
        setPermissionsTeamId($company->id);
        $owner->refresh();
        $this->assertTrue($owner->hasRole('administrator'));
        $this->assertTrue($owner->can('settings.manage'));

        $adminRole = Role::query()
            ->where('name', 'administrator')
            ->where(config('permission.column_names.team_foreign_key'), $company->id)
            ->first();
        $this->assertNotNull($adminRole);
        $this->assertGreaterThan(0, $adminRole->permissions()->count());
    }
}
