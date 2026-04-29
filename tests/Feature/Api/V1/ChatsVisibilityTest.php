<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Models\Chat;
use App\Models\ChatAssignment;
use App\Models\Message;
use App\Models\User;
use App\Models\WhatsappSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class ChatsVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['administrator', 'manager', 'employee'] as $roleName) {
            Role::findOrCreate($roleName);
        }
    }

    public function test_assigned_employee_sees_chat_in_index_and_show(): void
    {
        $user = User::factory()->create();
        $user->assignRole('employee');
        Sanctum::actingAs($user);

        $session = WhatsappSession::factory()->create();
        $chat = Chat::factory()->create(['whatsapp_session_id' => $session->id]);

        ChatAssignment::create([
            'chat_id' => $chat->id,
            'user_id' => $user->id,
            'assigned_by' => $user->id,
        ]);

        Message::query()->create([
            'chat_id' => $chat->id,
            'whatsapp_session_id' => $session->id,
            'direction' => 'inbound',
            'type' => 'chat',
            'body' => 'seed',
            'message_timestamp' => now(),
        ]);

        $list = $this->getJson('/api/v1/chats');
        $list->assertOk();
        $this->assertTrue(
            collect($list->json('data'))->pluck('id')->contains($chat->id),
        );

        $this->getJson("/api/v1/chats/{$chat->id}")->assertOk();
    }

    public function test_unassigned_employee_gets_forbidden_on_show(): void
    {
        $user = User::factory()->create();
        $user->assignRole('employee');
        Sanctum::actingAs($user);

        $session = WhatsappSession::factory()->create();
        $chat = Chat::factory()->create(['whatsapp_session_id' => $session->id]);

        $this->getJson("/api/v1/chats/{$chat->id}")->assertForbidden();
    }

    public function test_administrator_can_view_any_chat(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('administrator');
        Sanctum::actingAs($admin);

        $session = WhatsappSession::factory()->create();
        $chat = Chat::factory()->create(['whatsapp_session_id' => $session->id]);

        $this->getJson("/api/v1/chats/{$chat->id}")->assertOk();
    }
}
