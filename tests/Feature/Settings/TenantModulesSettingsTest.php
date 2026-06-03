<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class TenantModulesSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('administrator');
    }

    public function test_administrator_can_toggle_modules_from_system_settings(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('administrator');

        $this->actingAs($admin)
            ->postJson(route('settings.system.modules.update'), [
                'modules' => [
                    'module_clients' => false,
                    'module_broadcasts' => true,
                ],
            ])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertSame('off', SystemSetting::getValue('module_clients'));
        $this->assertSame('on', SystemSetting::getValue('module_broadcasts'));
    }

    public function test_employee_cannot_update_modules(): void
    {
        Role::findOrCreate('employee');

        $employee = User::factory()->create();
        $employee->assignRole('employee');

        $this->actingAs($employee)
            ->postJson(route('settings.system.modules.update'), [
                'modules' => [
                    'module_clients' => false,
                ],
            ])
            ->assertForbidden();
    }
}
