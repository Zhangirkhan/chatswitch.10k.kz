<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Events\NewMessageReceived;
use App\Models\Chat;
use App\Models\Message;
use App\Models\User;
use App\Models\WhatsappSession;
use App\Support\ChatBroadcastAudience;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class ChatBroadcastAudienceTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_ids_without_roles_does_not_throw(): void
    {
        $employee = User::factory()->create();
        $session = WhatsappSession::factory()->create();
        $chat = Chat::factory()->create(['whatsapp_session_id' => $session->id]);

        $ids = ChatBroadcastAudience::userIdsWithAccessToChat($chat);

        $this->assertContains($employee->id, $ids);
    }

    public function test_user_ids_includes_administrator_when_role_exists(): void
    {
        Role::findOrCreate('administrator');
        $admin = User::factory()->create();
        $admin->assignRole('administrator');

        $session = WhatsappSession::factory()->create();
        $chat = Chat::factory()->create(['whatsapp_session_id' => $session->id]);

        $ids = ChatBroadcastAudience::userIdsWithAccessToChat($chat);

        $this->assertContains($admin->id, $ids);
    }

    public function test_new_message_broadcast_channels_resolve_without_roles(): void
    {
        $session = WhatsappSession::factory()->create();
        $chat = Chat::factory()->create(['whatsapp_session_id' => $session->id]);
        $message = Message::query()->create([
            'chat_id' => $chat->id,
            'company_id' => $chat->company_id,
            'whatsapp_session_id' => $session->id,
            'direction' => 'inbound',
            'body' => 'Привет',
            'type' => 'chat',
            'message_timestamp' => now(),
        ]);

        $channels = (new NewMessageReceived($message, $chat->id))->broadcastOn();

        $this->assertNotEmpty($channels);
    }
}
