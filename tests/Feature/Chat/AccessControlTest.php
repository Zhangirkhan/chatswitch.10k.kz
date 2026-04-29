<?php

declare(strict_types=1);

namespace Tests\Feature\Chat;

use App\Models\Chat;
use App\Models\ChatAssignment;
use App\Models\Department;
use App\Models\User;
use App\Models\WhatsappSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class AccessControlTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['administrator', 'manager', 'employee'] as $r) {
            Role::findOrCreate($r);
        }
    }

    public function test_administrator_can_view_any_chat(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('administrator');

        $session = WhatsappSession::factory()->create();
        $chat = Chat::factory()->create(['whatsapp_session_id' => $session->id]);

        $this->assertTrue($admin->can('view', $chat));
    }

    public function test_employee_cannot_access_unassigned_chat(): void
    {
        $user = User::factory()->create();
        $user->assignRole('employee');

        $session = WhatsappSession::factory()->create();
        $chat = Chat::factory()->create(['whatsapp_session_id' => $session->id]);

        $this->assertFalse($user->can('view', $chat));

        $this->actingAs($user, 'web')
            ->get("/chats/{$chat->id}")
            ->assertForbidden();
    }

    public function test_employee_can_access_assigned_chat(): void
    {
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

    public function test_employee_cannot_sync_chat_departments(): void
    {
        $user = User::factory()->create();
        $user->assignRole('employee');

        $dept = Department::query()->create([
            'name' => 'Отдел тест',
            'description' => null,
            'is_active' => true,
        ]);

        $session = WhatsappSession::factory()->create();
        $chat = Chat::factory()->create(['whatsapp_session_id' => $session->id]);

        ChatAssignment::create([
            'chat_id' => $chat->id,
            'user_id' => $user->id,
            'assigned_by' => $user->id,
        ]);

        $this->assertFalse($user->can('syncDepartments', $chat));

        $this->actingAs($user, 'web')
            ->postJson(route('chats.departments.sync', $chat), [
                'department_ids' => [$dept->id],
            ])
            ->assertForbidden();
    }
}
