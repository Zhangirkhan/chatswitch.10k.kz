<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\Company;
use App\Models\User;
use App\Support\TenantRoles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class BackfillTenantOwnersCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('administrator', 'web');
    }

    public function test_backfill_assigns_first_administrator_to_company_without_owner(): void
    {
        $company = Company::query()->create([
            'name' => 'No Owner Co',
            'slug' => 'no-owner-co',
            'is_active' => true,
            'subscription_status' => 'active',
        ]);

        TenantRoles::ensureDefaultRolesForCompany($company);

        $manager = User::factory()->create([
            'company_id' => $company->id,
            'is_active' => true,
            'email' => 'manager@no-owner-co.kz',
        ]);
        TenantRoles::assign($manager, 'manager');

        $admin = User::factory()->create([
            'company_id' => $company->id,
            'is_active' => true,
            'email' => 'admin@no-owner-co.kz',
        ]);
        TenantRoles::assign($admin, 'administrator');

        $this->artisan('tenants:backfill-owners', ['--company' => 'no-owner-co'])
            ->assertSuccessful();

        $company->refresh();
        $this->assertSame($admin->id, $company->owner_user_id);
    }

    public function test_backfill_skips_company_without_administrator(): void
    {
        $company = Company::query()->create([
            'name' => 'No Admin Co',
            'slug' => 'no-admin-co',
            'is_active' => true,
            'subscription_status' => 'active',
        ]);

        TenantRoles::ensureDefaultRolesForCompany($company);

        User::factory()->create([
            'company_id' => $company->id,
            'is_active' => true,
            'email' => 'employee@no-admin-co.kz',
        ]);

        $this->artisan('tenants:backfill-owners')
            ->assertFailed();

        $this->assertNull($company->fresh()->owner_user_id);
    }

    public function test_backfill_dry_run_does_not_persist_owner(): void
    {
        $company = Company::query()->create([
            'name' => 'Dry Run Co',
            'slug' => 'dry-run-co',
            'is_active' => true,
            'subscription_status' => 'active',
        ]);

        TenantRoles::ensureDefaultRolesForCompany($company);

        $admin = User::factory()->create([
            'company_id' => $company->id,
            'is_active' => true,
            'email' => 'admin@dry-run-co.kz',
        ]);
        TenantRoles::assign($admin, 'administrator');

        $this->artisan('tenants:backfill-owners', ['--company' => 'dry-run-co', '--dry-run' => true])
            ->assertSuccessful();

        $this->assertNull($company->fresh()->owner_user_id);
    }

    public function test_backfill_company_filter_targets_single_tenant(): void
    {
        $target = Company::query()->create([
            'name' => 'Target Co',
            'slug' => 'target-co',
            'is_active' => true,
            'subscription_status' => 'active',
        ]);
        $other = Company::query()->create([
            'name' => 'Other Co',
            'slug' => 'other-co',
            'is_active' => true,
            'subscription_status' => 'active',
        ]);

        foreach ([$target, $other] as $company) {
            TenantRoles::ensureDefaultRolesForCompany($company);
            $admin = User::factory()->create([
                'company_id' => $company->id,
                'is_active' => true,
            ]);
            TenantRoles::assign($admin, 'administrator');
        }

        $this->artisan('tenants:backfill-owners', ['--company' => 'target-co'])
            ->assertSuccessful();

        $this->assertNotNull($target->fresh()->owner_user_id);
        $this->assertNull($other->fresh()->owner_user_id);
    }
}
