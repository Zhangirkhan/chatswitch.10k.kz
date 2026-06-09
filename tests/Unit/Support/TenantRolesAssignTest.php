<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Models\Chat;
use App\Models\ChatAssignment;
use App\Models\User;
use App\Models\WhatsappSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class TenantRolesAssignTest extends TestCase
{
    use RefreshDatabase;

    public function test_assign_employee_role_is_detected(): void
    {
        $user = User::factory()->create();
        $this->assignTenantRole($user, 'employee');

        $this->assertTrue($user->hasRole('employee'));
        $this->assertTrue($user->can('chats.view_assigned'));
    }

    public function test_raw_assign_role_after_find_or_create_has_permissions(): void
    {
        foreach (['administrator', 'manager', 'employee'] as $role) {
            \Spatie\Permission\Models\Role::findOrCreate($role);
        }

        $user = User::factory()->create();
        $user->assignRole('employee');

        $this->assertTrue($user->hasRole('employee'));
        $this->assertTrue($user->can('chats.view_assigned'));
    }

    public function test_employee_can_view_assigned_chat_like_access_control_test(): void
    {
        foreach (['administrator', 'manager', 'employee'] as $role) {
            \Spatie\Permission\Models\Role::findOrCreate($role);
        }

        $user = User::factory()->create();
        $user->assignRole('employee');

        $session = WhatsappSession::factory()->create();
        $chat = Chat::factory()->create(['whatsapp_session_id' => $session->id]);

        ChatAssignment::create([
            'chat_id' => $chat->id,
            'user_id' => $user->id,
            'assigned_by' => $user->id,
        ]);

        $this->assertTrue($user->can('view', $chat));
    }
}
