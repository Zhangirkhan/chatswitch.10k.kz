<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Company;
use App\Support\TenantRoles;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

final class TenantRoleService
{
    /**
     * @return array{
     *     groups: array<string, array{label: string, permissions: array<string, string>}>,
     *     protected_role_names: list<string>
     * }
     */
    public function permissionCatalog(): array
    {
        return [
            'groups' => config('tenant_permissions.groups', []),
            'protected_role_names' => config('tenant_permissions.protected_role_names', []),
        ];
    }

    /**
     * @return Collection<int, array{
     *     id: int,
     *     name: string,
     *     guard_name: string,
     *     users_count: int,
     *     permissions: list<string>,
     *     is_protected: bool
     * }>
     */
    public function listForCompany(int $companyId): Collection
    {
        $teamKey = config('permission.column_names.team_foreign_key');
        $protected = config('tenant_permissions.protected_role_names', []);

        return Role::query()
            ->where($teamKey, $companyId)
            ->where('guard_name', 'web')
            ->with('permissions')
            ->withCount('users')
            ->orderBy('name')
            ->get()
            ->map(static function (Role $role) use ($protected): array {
                return [
                    'id' => (int) $role->id,
                    'name' => $role->name,
                    'guard_name' => $role->guard_name,
                    'users_count' => (int) $role->users_count,
                    'permissions' => $role->permissions->pluck('name')->values()->all(),
                    'is_protected' => in_array($role->name, $protected, true),
                ];
            });
    }

    /**
     * @param  list<string>  $permissionNames
     * @return array{id: int, name: string, permissions: list<string>}
     */
    public function create(int $companyId, string $name, array $permissionNames): array
    {
        $this->assertValidPermissions($permissionNames);

        return $this->withTeam($companyId, function () use ($companyId, $name, $permissionNames): array {
            $role = Role::create([
                'name' => $name,
                'guard_name' => 'web',
                config('permission.column_names.team_foreign_key') => $companyId,
            ]);

            $this->syncRolePermissions($role, $permissionNames);

            return [
                'id' => (int) $role->id,
                'name' => $role->name,
                'permissions' => $permissionNames,
            ];
        });
    }

    /**
     * @param  list<string>  $permissionNames
     * @return array{id: int, name: string, permissions: list<string>}
     */
    public function update(Role $role, string $name, array $permissionNames): array
    {
        $this->assertRoleBelongsToCompany($role);
        $this->assertValidPermissions($permissionNames);

        return $this->withTeam((int) $role->{config('permission.column_names.team_foreign_key')}, function () use ($role, $name, $permissionNames): array {
            $role->update(['name' => $name]);
            $this->syncRolePermissions($role, $permissionNames);
            $this->assertCompanyRetainsSettingsManage($role);

            return [
                'id' => (int) $role->id,
                'name' => $role->name,
                'permissions' => $permissionNames,
            ];
        });
    }

    public function delete(Role $role): void
    {
        $this->assertRoleBelongsToCompany($role);

        if (in_array($role->name, config('tenant_permissions.protected_role_names', []), true)) {
            throw ValidationException::withMessages([
                'role' => 'Системную роль нельзя удалить.',
            ]);
        }

        if ($role->users()->count() > 0) {
            throw ValidationException::withMessages([
                'role' => 'Нельзя удалить роль, назначенную сотрудникам.',
            ]);
        }

        $companyId = (int) $role->{config('permission.column_names.team_foreign_key')};

        $this->withTeam($companyId, function () use ($role, $companyId): void {
            if ($role->hasPermissionTo('settings.manage')) {
                $remaining = Role::query()
                    ->where(config('permission.column_names.team_foreign_key'), $companyId)
                    ->whereKeyNot($role->id)
                    ->whereHas('permissions', fn ($q) => $q->where('name', 'settings.manage'))
                    ->count();

                if ($remaining === 0) {
                    throw ValidationException::withMessages([
                        'role' => 'Нельзя удалить последнюю роль с правом управления настройками.',
                    ]);
                }
            }

            $role->delete();
        });
    }

    public function ensureForCompany(Company $company): void
    {
        $teamKey = config('permission.column_names.team_foreign_key');

        $hasTenantRoles = Role::query()
            ->where($teamKey, $company->id)
            ->where('guard_name', 'web')
            ->exists();

        if (! $hasTenantRoles) {
            $this->migrateCompany($company);
        }
    }

    public function migrateCompany(Company $company): void
    {
        TenantRoles::ensureDefaultRolesForCompany($company);

        $teamKey = config('permission.column_names.team_foreign_key');
        $roles = Role::query()
            ->where($teamKey, $company->id)
            ->whereIn('name', ['administrator', 'manager', 'employee'])
            ->get()
            ->keyBy('name');

        $users = $company->users()->get();
        $modelHasRoles = config('permission.table_names.model_has_roles');

        foreach ($users as $user) {
            $roleName = DB::table($modelHasRoles)
                ->join('roles', "{$modelHasRoles}.role_id", '=', 'roles.id')
                ->where("{$modelHasRoles}.model_type", $user->getMorphClass())
                ->where("{$modelHasRoles}.model_id", $user->id)
                ->orderBy("{$modelHasRoles}.{$teamKey}")
                ->value('roles.name');

            if (! is_string($roleName) || ! $roles->has($roleName)) {
                continue;
            }

            TenantRoles::assign($user, $roleName);
        }

        Role::query()
            ->whereNull($teamKey)
            ->whereIn('name', ['administrator', 'manager', 'employee'])
            ->whereDoesntHave('users')
            ->delete();
    }

    /**
     * @param  list<string>  $permissionNames
     */
    private function syncRolePermissions(Role $role, array $permissionNames): void
    {
        $permissions = Permission::query()
            ->whereIn('name', TenantRoles::resolvePermissionNames($permissionNames))
            ->where('guard_name', 'web')
            ->get();

        $role->syncPermissions($permissions);
    }

    /**
     * @param  list<string>  $permissionNames
     */
    private function assertValidPermissions(array $permissionNames): void
    {
        $allowed = TenantRoles::allPermissionNames();

        foreach ($permissionNames as $permission) {
            if (! in_array($permission, $allowed, true)) {
                throw ValidationException::withMessages([
                    'permissions' => "Неизвестное право: {$permission}",
                ]);
            }
        }
    }

    private function assertRoleBelongsToCompany(Role $role): void
    {
        $teamKey = config('permission.column_names.team_foreign_key');
        $companyId = $role->{$teamKey};

        if ($companyId === null) {
            throw ValidationException::withMessages([
                'role' => 'Роль не принадлежит компании.',
            ]);
        }
    }

    private function assertCompanyRetainsSettingsManage(Role $role): void
    {
        if ($role->hasPermissionTo('settings.manage')) {
            return;
        }

        $teamKey = config('permission.column_names.team_foreign_key');
        $companyId = (int) $role->{$teamKey};

        $remaining = Role::query()
            ->where($teamKey, $companyId)
            ->whereHas('permissions', fn ($q) => $q->where('name', 'settings.manage'))
            ->count();

        if ($remaining === 0) {
            throw ValidationException::withMessages([
                'permissions' => 'В компании должна остаться хотя бы одна роль с правом settings.manage.',
            ]);
        }
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function withTeam(int $companyId, callable $callback): mixed
    {
        $previousTeamId = app(PermissionRegistrar::class)->getPermissionsTeamId();
        setPermissionsTeamId($companyId);

        try {
            return DB::transaction($callback);
        } finally {
            setPermissionsTeamId($previousTeamId);
        }
    }
}
