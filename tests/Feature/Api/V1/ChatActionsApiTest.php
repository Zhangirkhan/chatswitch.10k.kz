<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Models\Chat;
use App\Models\ChatAssignment;
use App\Models\User;
use App\Models\WhatsappSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class ChatActionsApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['administrator', 'manager', 'employee'] as $roleName) {
            Role::findOrCreate($roleName);
        }
    }

    public function test_toggle_pin_via_bearer_token(): void
    {
        $user = User::factory()->create();
        $user->assignRole('administrator');

        $session = WhatsappSession::factory()->create();
        $chat = Chat::factory()->create([
            'whatsapp_session_id' => $session->id,
            'is_pinned' => false,
        ]);

        Sanctum::actingAs($user);

        $this->postJson("/api/v1/chats/{$chat->id}/pin")
            ->assertOk()
            ->assertJson(['success' => true, 'is_pinned' => true]);

        $this->assertTrue($chat->fresh()->is_pinned);
    }

    public function test_employee_without_assignment_cannot_pin(): void
    {
        $user = User::factory()->create();
        $user->assignRole('employee');

        $session = WhatsappSession::factory()->create();
        $chat = Chat::factory()->create(['whatsapp_session_id' => $session->id]);

        Sanctum::actingAs($user);

        $this->postJson("/api/v1/chats/{$chat->id}/pin")->assertForbidden();
    }

    public function test_assign_user_to_chat(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('administrator');

        $employee = User::factory()->create();
        $employee->assignRole('employee');

        $session = WhatsappSession::factory()->create();
        $chat = Chat::factory()->create(['whatsapp_session_id' => $session->id]);

        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/chats/{$chat->id}/assign", [
            'user_id' => $employee->id,
        ])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertDatabaseHas('chat_assignments', [
            'chat_id' => $chat->id,
            'user_id' => $employee->id,
        ]);
    }
}
