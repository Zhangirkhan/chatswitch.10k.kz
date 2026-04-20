<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

final class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $roles = ['administrator', 'manager', 'employee'];

        foreach ($roles as $roleName) {
            Role::findOrCreate($roleName, 'web');
        }

        $admin = User::firstOrCreate(
            ['email' => 'admin@chatswitch.10k.kz'],
            [
                'name' => 'Admin',
                'password' => Hash::make('password'),
                'is_active' => true,
            ],
        );

        $admin->assignRole('administrator');
    }
}
