<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Models\Chat;
use App\Models\ChatAssignment;
use App\Models\Message;
use App\Models\User;
use App\Models\WhatsappSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class ChatMessagesAfterIdApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['administrator', 'manager', 'employee'] as $roleName) {
            Role::findOrCreate($roleName);
        }
    }

    public function test_after_id_returns_only_newer_messages_in_asc_order(): void
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

        $token = $this->loginToken($user);

        $older = $this->createMessage($chat, $session, 'Old');
        $boundary = $this->createMessage($chat, $session, 'Boundary');
        $newerOne = $this->createMessage($chat, $session, 'New 1');
        $newerTwo = $this->createMessage($chat, $session, 'New 2');
        $newerThree = $this->createMessage($chat, $session, 'New 3');

        $response = $this->withToken($token)->getJson("/api/v1/chats/{$chat->id}/messages?after_id={$boundary->id}&limit=50");

        $response->assertOk();
        $response->assertJsonCount(3, 'messages');
        $response->assertJsonPath('messages.0.id', $newerOne->id);
        $response->assertJsonPath('messages.1.id', $newerTwo->id);
        $response->assertJsonPath('messages.2.id', $newerThree->id);
        $this->assertNotContains($older->id, array_column($response->json('messages'), 'id'));
    }

    public function test_after_id_on_empty_chat_returns_empty_array(): void
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

        $token = $this->loginToken($user);

        $this->withToken($token)->getJson("/api/v1/chats/{$chat->id}/messages?after_id=999999")
            ->assertOk()
            ->assertJsonPath('messages', []);
    }

    public function test_after_id_is_idempotent_on_repeat_poll(): void
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

        $token = $this->loginToken($user);

        $first = $this->createMessage($chat, $session, 'One');
        $second = $this->createMessage($chat, $session, 'Two');

        $url = "/api/v1/chats/{$chat->id}/messages?after_id={$first->id}";

        $firstPoll = $this->withToken($token)->getJson($url)->assertOk()->json('messages');
        $secondPoll = $this->withToken($token)->getJson($url)->assertOk()->json('messages');

        $this->assertSame($firstPoll, $secondPoll);
        $this->assertCount(1, $firstPoll);
        $this->assertSame($second->id, $firstPoll[0]['id']);
    }

    public function test_after_id_cannot_be_combined_with_before_cursor(): void
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

        $token = $this->loginToken($user);

        $this->withToken($token)->getJson("/api/v1/chats/{$chat->id}/messages?after_id=1&before_id=2")
            ->assertStatus(400);
    }

    private function createMessage(Chat $chat, WhatsappSession $session, string $body): Message
    {
        return Message::create([
            'chat_id' => $chat->id,
            'whatsapp_session_id' => $session->id,
            'direction' => 'inbound',
            'type' => 'chat',
            'body' => $body,
            'ack' => 'delivered',
            'message_timestamp' => now(),
        ]);
    }

    private function loginToken(User $user): string
    {
        return (string) $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk()->json('token');
    }
}
