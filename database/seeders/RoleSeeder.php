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
            ['email' => 'admin@accel.kz'],
            [
                'name' => 'Admin',
                'password' => Hash::make('password'),
                'is_active' => true,
            ],
        );

        $admin->assignRole('administrator');

        $superEmail = (string) config('tenancy.super_admin_email', 'super@accel.kz');
        $super = User::firstOrCreate(
            ['email' => $superEmail, 'company_id' => null],
            [
                'name' => 'Super Admin',
                'password' => Hash::make('password'),
                'is_active' => true,
                'is_super_admin' => true,
            ],
        );
        $super->forceFill(['is_super_admin' => true, 'company_id' => null])->save();
    }
}
