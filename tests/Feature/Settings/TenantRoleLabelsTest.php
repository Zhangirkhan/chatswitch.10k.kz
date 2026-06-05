<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Models\User;
use App\Support\TenantRoleLabels;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class TenantRoleLabelsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['administrator', 'manager', 'employee'] as $role) {
            Role::findOrCreate($role);
        }
    }

    public function test_administrator_can_save_role_labels_on_onboarding(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('administrator');

        $this->actingAs($admin)
            ->post(route('settings.onboarding.roles'), [
                'administrator' => 'Директор',
                'manager' => 'Менеджер',
                'employee' => 'Монтажник',
            ])
            ->assertRedirect(route('settings.onboarding'));

        $this->assertTrue(TenantRoleLabels::isConfigured());
        $this->assertSame('Директор', TenantRoleLabels::label('administrator'));
        $this->assertSame('Монтажник', TenantRoleLabels::label('employee'));
    }

    public function test_employee_cannot_save_role_labels(): void
    {
        $employee = User::factory()->create();
        $employee->assignRole('employee');

        $this->actingAs($employee)
            ->post(route('settings.onboarding.roles'), [
                'administrator' => 'Директор',
                'manager' => 'Менеджер',
                'employee' => 'Монтажник',
            ])
            ->assertForbidden();
    }
}
