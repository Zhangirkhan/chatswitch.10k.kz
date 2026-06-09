<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Models\Company;
use App\Models\User;
use App\Services\TenantRoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class TenantRoleMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_migrate_roles_preserves_user_access(): void
    {
        $company = $this->createTenantCompany(['slug' => 'migrate-roles']);

        $user = User::factory()->create(['company_id' => $company->id]);
        setPermissionsTeamId(null);
        $legacyRole = Role::query()->create([
            'name' => 'manager',
            'guard_name' => 'web',
            config('permission.column_names.team_foreign_key') => null,
        ]);

        \Illuminate\Support\Facades\DB::table(config('permission.table_names.model_has_roles'))->insert([
            'role_id' => $legacyRole->id,
            'model_type' => $user->getMorphClass(),
            'model_id' => $user->id,
            config('permission.column_names.team_foreign_key') => $company->id,
        ]);

        app(TenantRoleService::class)->migrateCompany($company);

        setPermissionsTeamId($company->id);
        $user->refresh();

        $this->assertTrue($user->hasRole('manager'));
        $this->assertTrue($user->can('chats.view_department'));
        $this->assertFalse($user->can('settings.manage'));
    }
}
