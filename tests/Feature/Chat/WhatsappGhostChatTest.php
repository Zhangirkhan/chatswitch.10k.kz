<?php

declare(strict_types=1);

namespace Tests\Feature\Chat;

use App\Jobs\AnalyzeChatFunnelJob;
use App\Jobs\GenerateAiReplyJob;
use App\Jobs\ProcessWhatsappInboundJob;
use App\Jobs\RunAiFunnelOrchestratorJob;
use App\Models\Chat;
use App\Models\Message;
use App\Models\User;
use App\Models\WhatsappSession;
use App\Services\ChatService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class WhatsappGhostChatTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['administrator', 'manager', 'employee'] as $role) {
            Role::findOrCreate($role);
        }

        config(['funnel.department_routing.enabled' => false]);
    }

    public function test_e2e_notification_does_not_create_chat(): void
    {
        WhatsappSession::factory()->create(['session_name' => 'ghost-test']);

        Bus::fake([
            GenerateAiReplyJob::class,
            RunAiFunnelOrchestratorJob::class,
            AnalyzeChatFunnelJob::class,
        ]);

        $job = new ProcessWhatsappInboundJob([
            'session' => 'ghost-test',
            'type' => 'e2e_notification',
            'chatId' => '223840860954751@lid',
            'chatName' => '+7 700 123 4568',
            'from' => '223840860954751@lid',
            'senderAuthorJid' => '223840860954751@lid',
            'senderPhone' => '77001234568',
            'body' => '',
            'messageId' => 'ghost-e2e-1',
            'timestamp' => time(),
            'isGroup' => false,
        ]);

        $this->app->call([$job, 'handle']);

        $this->assertSame(0, Chat::count());
        $this->assertSame(0, Message::count());
    }

    public function test_lid_contact_does_not_store_fake_phone_number(): void
    {
        $contact = app(ChatService::class)->findOrCreateContact([
            'senderAuthorJid' => '223840860954751@lid',
            'from' => '223840860954751@lid',
            'senderPhone' => '77001234568',
            'senderName' => null,
        ]);

        $this->assertSame('223840860954751@lid', $contact->whatsapp_id);
        $this->assertFalse(\App\Support\PhoneFormatter::isPlausibleE164($contact->phone_number));
    }

    public function test_archived_feed_excludes_ghost_chats_with_only_service_messages(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('administrator');

        $session = WhatsappSession::factory()->create();

        $ghost = Chat::factory()->create([
            'whatsapp_session_id' => $session->id,
            'whatsapp_chat_id' => '223840860954751@lid',
            'chat_name' => '+7 700 123 4568',
            'is_archived' => true,
            'last_message_at' => now(),
        ]);

        Message::create([
            'chat_id' => $ghost->id,
            'whatsapp_session_id' => $session->id,
            'direction' => 'inbound',
            'type' => 'e2e_notification',
            'body' => '',
            'ack' => 'delivered',
            'message_timestamp' => now(),
        ]);

        $real = Chat::factory()->create([
            'whatsapp_session_id' => $session->id,
            'is_archived' => true,
            'last_message_at' => now(),
        ]);

        Message::create([
            'chat_id' => $real->id,
            'whatsapp_session_id' => $session->id,
            'direction' => 'inbound',
            'type' => 'chat',
            'body' => 'Привет',
            'ack' => 'delivered',
            'message_timestamp' => now(),
        ]);

        $response = $this->actingAs($admin)
            ->getJson(route('chats.feed', ['archived' => 1]));

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($real->id, $ids);
        $this->assertNotContains($ghost->id, $ids);
    }

    public function test_prune_command_removes_ghost_chats(): void
    {
        $session = WhatsappSession::factory()->create();

        $ghost = Chat::factory()->create([
            'whatsapp_session_id' => $session->id,
            'whatsapp_chat_id' => '223840860954751@lid',
            'chat_name' => '+7 700 123 4568',
            'last_message_at' => now(),
        ]);

        Message::create([
            'chat_id' => $ghost->id,
            'whatsapp_session_id' => $session->id,
            'direction' => 'inbound',
            'type' => 'e2e_notification',
            'body' => '',
            'ack' => 'delivered',
            'message_timestamp' => now(),
        ]);

        $this->artisan('chats:prune-ghost-whatsapp')
            ->assertSuccessful();

        $this->assertDatabaseMissing('chats', ['id' => $ghost->id]);
    }

    public function test_chat_timeline_excludes_service_messages(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('administrator');

        $session = WhatsappSession::factory()->create();
        $chat = Chat::factory()->create([
            'whatsapp_session_id' => $session->id,
            'whatsapp_chat_id' => '77001112233@c.us',
        ]);

        Message::create([
            'chat_id' => $chat->id,
            'whatsapp_session_id' => $session->id,
            'direction' => 'inbound',
            'type' => 'e2e_notification',
            'body' => '',
            'ack' => 'delivered',
            'message_timestamp' => now()->subMinute(),
        ]);

        Message::create([
            'chat_id' => $chat->id,
            'whatsapp_session_id' => $session->id,
            'direction' => 'inbound',
            'type' => 'chat',
            'body' => 'Реальное сообщение',
            'ack' => 'delivered',
            'message_timestamp' => now(),
        ]);

        $response = $this->actingAs($admin)
            ->getJson(route('api.chats.timeline', $chat));

        $response->assertOk();
        $this->assertCount(1, $response->json('messages'));
        $this->assertSame('Реальное сообщение', $response->json('messages.0.body'));
    }
}
