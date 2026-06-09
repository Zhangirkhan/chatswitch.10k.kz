<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;

final class TenantPermissionSeeder extends Seeder
{
    public function run(): void
    {
        $guard = 'web';

        foreach ($this->permissionNames() as $name) {
            Permission::findOrCreate($name, $guard);
        }
    }

    /**
     * @return list<string>
     */
    public static function permissionNames(): array
    {
        $names = [];

        foreach (config('tenant_permissions.groups', []) as $group) {
            foreach (array_keys($group['permissions'] ?? []) as $permission) {
                $names[] = $permission;
            }
        }

        return array_values(array_unique($names));
    }
}
