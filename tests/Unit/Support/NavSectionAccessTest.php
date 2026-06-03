<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Models\SystemSetting;
use App\Models\User;
use App\Support\NavSectionAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class NavSectionAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['administrator', 'manager', 'employee'] as $role) {
            Role::findOrCreate($role);
        }
    }

    public function test_employee_does_not_see_broadcasts_in_nav(): void
    {
        $employee = User::factory()->create();
        $employee->assignRole('employee');

        $nav = NavSectionAccess::visibleFor($employee);

        $this->assertTrue($nav['chats']);
        $this->assertTrue($nav['clients']);
        $this->assertFalse($nav['broadcasts']);
    }

    public function test_manager_sees_broadcasts_when_module_enabled(): void
    {
        $manager = User::factory()->create();
        $manager->assignRole('manager');

        SystemSetting::setValue('module_broadcasts', 'on');

        $nav = NavSectionAccess::visibleFor($manager);

        $this->assertTrue($nav['broadcasts']);
    }

    public function test_clients_hidden_when_module_disabled(): void
    {
        $employee = User::factory()->create();
        $employee->assignRole('employee');

        SystemSetting::setValue('module_clients', 'off');

        $nav = NavSectionAccess::visibleFor($employee);

        $this->assertFalse($nav['clients']);
    }

    public function test_clients_route_returns_forbidden_when_module_disabled(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('administrator');

        SystemSetting::setValue('module_clients', 'off');

        $this->actingAs($admin)
            ->get(route('clients.index'))
            ->assertForbidden();
    }
}
