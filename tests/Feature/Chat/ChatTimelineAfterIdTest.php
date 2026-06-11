<?php

declare(strict_types=1);

namespace Tests\Feature\Chat;

use App\Models\Chat;
use App\Models\Message;
use App\Models\User;
use App\Models\WhatsappSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class ChatTimelineAfterIdTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['administrator', 'manager', 'employee'] as $roleName) {
            Role::findOrCreate($roleName);
        }
    }

    public function test_timeline_after_id_returns_only_newer_messages(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('administrator');

        $session = WhatsappSession::factory()->create();
        $chat = Chat::factory()->create(['whatsapp_session_id' => $session->id]);

        $older = $this->createMessage($chat, $session, 'Old');
        $boundary = $this->createMessage($chat, $session, 'Boundary');
        $newerOne = $this->createMessage($chat, $session, 'New 1');
        $newerTwo = $this->createMessage($chat, $session, 'New 2');

        $response = $this->actingAs($admin)->getJson(route('api.chats.timeline', $chat->id).'?'.http_build_query([
            'after_id' => $boundary->id,
            'limit' => 50,
        ]));

        $response->assertOk();
        $response->assertJsonCount(2, 'messages');
        $response->assertJsonPath('messages.0.id', $newerOne->id);
        $response->assertJsonPath('messages.1.id', $newerTwo->id);
        $this->assertNotContains($older->id, array_column($response->json('messages'), 'id'));
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
}
