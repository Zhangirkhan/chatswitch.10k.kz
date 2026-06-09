<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Models\User;
use App\Services\TenantRoleService;
use App\Support\TenantRoles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class TenantRoleAutoEnsureTest extends TestCase
{
    use RefreshDatabase;

    public function test_ensure_for_company_creates_tenant_scoped_roles_when_only_global_exist(): void
    {
        $company = $this->createTenantCompany(['slug' => 'auto-ensure-roles']);
        $teamKey = config('permission.column_names.team_foreign_key');

        Role::query()->where($teamKey, $company->id)->delete();

        $this->assertFalse(
            Role::query()->where($teamKey, $company->id)->exists(),
        );

        app(TenantRoleService::class)->ensureForCompany($company);

        $this->assertSame(
            ['administrator', 'employee', 'manager'],
            Role::query()
                ->where($teamKey, $company->id)
                ->orderBy('name')
                ->pluck('name')
                ->all(),
        );
    }

    public function test_users_page_auto_ensures_tenant_roles(): void
    {
        $this->withoutVite();

        $company = $this->createTenantCompany(['slug' => 'auto-ensure-users']);
        $teamKey = config('permission.column_names.team_foreign_key');

        Role::query()->where($teamKey, $company->id)->delete();

        $legacyRole = Role::query()->create([
            'name' => 'administrator',
            'guard_name' => 'web',
            $teamKey => null,
        ]);

        $admin = User::factory()->create(['company_id' => $company->id]);
        DB::table(config('permission.table_names.model_has_roles'))->insert([
            'role_id' => $legacyRole->id,
            'model_type' => $admin->getMorphClass(),
            'model_id' => $admin->id,
            $teamKey => $company->id,
        ]);

        $response = $this->actingAs($admin)->get('/settings/users');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Settings/Users')
            ->has('availableRoles', 3)
        );
    }
}
