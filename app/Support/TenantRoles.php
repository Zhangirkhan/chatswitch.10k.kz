<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Company;
use App\Models\User;
use Database\Seeders\TenantPermissionSeeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

final class TenantRoles
{
    /**
     * @return list<string>
     */
    public static function allPermissionNames(): array
    {
        return TenantPermissionSeeder::permissionNames();
    }

    /**
     * @param  list<string>  $permissionNames
     * @return list<string>
     */
    public static function resolvePermissionNames(array $permissionNames): array
    {
        if (in_array('*', $permissionNames, true)) {
            return self::allPermissionNames();
        }

        return array_values(array_unique($permissionNames));
    }

    public static function ensurePermissionsSeeded(): void
    {
        app(TenantPermissionSeeder::class)->run();
    }

    public static function ensureDefaultRolesForCompany(Company $company): void
    {
        self::ensurePermissionsSeeded();

        $teamKey = config('permission.column_names.team_foreign_key');
        $previousTeamId = app(PermissionRegistrar::class)->getPermissionsTeamId();
        setPermissionsTeamId($company->id);

        try {
            foreach (config('tenant_permissions.default_role_permissions', []) as $roleName => $permissionNames) {
                $role = Role::query()
                    ->where('name', $roleName)
                    ->where('guard_name', 'web')
                    ->where($teamKey, $company->id)
                    ->first();

                if ($role === null) {
                    $role = Role::query()->firstOrCreate([
                        'name' => $roleName,
                        'guard_name' => 'web',
                        $teamKey => $company->id,
                    ]);
                }

                $permissions = Permission::query()
                    ->whereIn('name', self::resolvePermissionNames($permissionNames))
                    ->where('guard_name', 'web')
                    ->get();
                $role->syncPermissions($permissions);
            }
        } finally {
            setPermissionsTeamId($previousTeamId);
        }
    }

    public static function assign(User $user, string $roleName): void
    {
        $companyId = $user->company_id;
        if ($companyId === null) {
            $user->assignRole($roleName);

            return;
        }

        self::syncForCompany($user, (int) $companyId, $roleName);
    }

    public static function syncForCompany(User $user, int $companyId, string $roleName): void
    {
        $teamKey = config('permission.column_names.team_foreign_key');
        $previousTeamId = app(PermissionRegistrar::class)->getPermissionsTeamId();
        setPermissionsTeamId($companyId);

        try {
            self::ensurePermissionsSeeded();

            $roleExists = Role::query()
                ->where('name', $roleName)
                ->where('guard_name', 'web')
                ->where($teamKey, $companyId)
                ->exists();

            if (! $roleExists) {
                $company = Company::query()->withoutGlobalScope('tenant')->findOrFail($companyId);
                self::ensureDefaultRolesForCompany($company);
            }

            $role = Role::query()
                ->where('name', $roleName)
                ->where('guard_name', 'web')
                ->where($teamKey, $companyId)
                ->firstOrFail();

            $user->syncRoles([$role]);
        } finally {
            setPermissionsTeamId($previousTeamId);
        }
    }

    /**
     * @deprecated Prefer {@see syncForCompany()} — kept for tenant routes that already seed per-company roles.
     */
    public static function assignWithTenantRoleRecord(User $user, string $roleName): void
    {
        $companyId = $user->company_id;
        if ($companyId === null) {
            $user->assignRole($roleName);

            return;
        }

        $previousTeamId = app(PermissionRegistrar::class)->getPermissionsTeamId();
        setPermissionsTeamId($companyId);

        try {
            $role = Role::query()
                ->where('name', $roleName)
                ->where('guard_name', 'web')
                ->where(config('permission.column_names.team_foreign_key'), $companyId)
                ->first();

            if ($role === null) {
                $company = Company::query()->withoutGlobalScope('tenant')->findOrFail($companyId);
                self::ensureDefaultRolesForCompany($company);
                $role = Role::query()
                    ->where('name', $roleName)
                    ->where('guard_name', 'web')
                    ->where(config('permission.column_names.team_foreign_key'), $companyId)
                    ->firstOrFail();
            }

            DB::table(config('permission.table_names.model_has_roles'))
                ->where('model_type', $user->getMorphClass())
                ->where('model_id', $user->id)
                ->where(config('permission.column_names.team_foreign_key'), $companyId)
                ->delete();

            $user->assignRole($role);
        } finally {
            setPermissionsTeamId($previousTeamId);
        }
    }
}
