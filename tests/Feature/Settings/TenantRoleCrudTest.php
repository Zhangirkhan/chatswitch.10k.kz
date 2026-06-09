<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class TenantRoleCrudTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_update_and_delete_custom_role(): void
    {
        $this->withoutVite();

        $admin = User::factory()->create();
        $this->assignTenantRole($admin, 'administrator');

        $this->actingAs($admin)
            ->post(route('settings.roles.store'), [
                'name' => 'Оператор смены',
                'permissions' => ['chats.view_assigned', 'chats.send'],
            ])
            ->assertRedirect(route('settings.roles'));

        $role = Role::query()
            ->where('name', 'Оператор смены')
            ->where(config('permission.column_names.team_foreign_key'), $admin->company_id)
            ->first();

        $this->assertNotNull($role);
        $this->assertTrue($role->hasPermissionTo('chats.send'));

        $this->actingAs($admin)
            ->put(route('settings.roles.update', $role), [
                'name' => 'Сменный оператор',
                'permissions' => ['chats.view_assigned', 'chats.send', 'contacts.view'],
            ])
            ->assertRedirect(route('settings.roles'));

        $role->refresh();
        $this->assertSame('Сменный оператор', $role->name);
        $this->assertTrue($role->hasPermissionTo('contacts.view'));

        $this->actingAs($admin)
            ->delete(route('settings.roles.destroy', $role))
            ->assertRedirect(route('settings.roles'));

        $this->assertNull($role->fresh());
    }

    public function test_cannot_delete_protected_role(): void
    {
        $admin = User::factory()->create();
        $this->assignTenantRole($admin, 'administrator');

        $role = Role::query()
            ->where('name', 'manager')
            ->where(config('permission.column_names.team_foreign_key'), $admin->company_id)
            ->firstOrFail();

        $this->actingAs($admin)
            ->from(route('settings.roles'))
            ->delete(route('settings.roles.destroy', $role))
            ->assertRedirect(route('settings.roles'))
            ->assertSessionHasErrors('role');
    }
}
