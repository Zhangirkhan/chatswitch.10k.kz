<?php

declare(strict_types=1);

namespace Tests\Feature\Contact;

use App\Jobs\AnalyzeChatFunnelJob;
use App\Jobs\GenerateAiReplyJob;
use App\Jobs\ProcessWhatsappInboundJob;
use App\Jobs\RunAiFunnelOrchestratorJob;
use App\Models\Chat;
use App\Models\Contact;
use App\Models\Message;
use App\Models\User;
use App\Models\WhatsappSession;
use App\Services\ChatService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class ChatClearBlocksWhatsappResyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('administrator');
        config(['funnel.department_routing.enabled' => false]);
        Carbon::setTestNow(Carbon::parse('2026-06-05 12:00:00', 'Asia/Almaty'));
        Bus::fake([
            GenerateAiReplyJob::class,
            RunAiFunnelOrchestratorJob::class,
            AnalyzeChatFunnelJob::class,
        ]);
    }

    public function test_clear_chat_sets_cutoff_and_blocks_old_inbound_resync(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('administrator');

        $session = WhatsappSession::factory()->create(['session_name' => 'default']);
        $contact = Contact::factory()->create();
        $chat = Chat::factory()->create([
            'contact_id' => $contact->id,
            'whatsapp_session_id' => $session->id,
            'whatsapp_chat_id' => '77001234567@c.us',
            'is_group' => false,
            'ai_enabled' => false,
            'funnel_tracking_enabled' => false,
        ]);

        $oldTimestamp = now()->subHours(2)->getTimestamp();

        $payload = [
            'session' => 'default',
            'chatId' => '77001234567@c.us',
            'chatName' => 'Client',
            'from' => '77001234567@c.us',
            'senderPhone' => '77001234567',
            'senderName' => 'Client',
            'body' => 'Есть?',
            'isGroup' => false,
            'timestamp' => $oldTimestamp,
            'messageId' => 'wa-msg-old-1',
            'type' => 'chat',
        ];

        $job = new ProcessWhatsappInboundJob($payload);
        $this->app->call([$job, 'handle']);
        $this->assertNotNull(Message::query()->where('whatsapp_message_id', 'wa-msg-old-1')->first());

        $this->actingAs($admin)->post(route('clients.clear', $contact))->assertOk();

        $chat->refresh();
        $this->assertNotNull($chat->messages_cleared_at);
        $contact->refresh();
        $this->assertNotNull($contact->messages_cleared_at);
        $this->assertNull(Message::query()->where('whatsapp_message_id', 'wa-msg-old-1')->first());

        $resyncJob = new ProcessWhatsappInboundJob($payload);
        $this->app->call([$resyncJob, 'handle']);

        $this->assertNull(Message::query()->where('whatsapp_message_id', 'wa-msg-old-1')->first());
        $chat->refresh();
        $this->assertNull($chat->last_message_at);
    }

    public function test_inbound_after_clear_is_still_accepted(): void
    {
        $session = WhatsappSession::factory()->create(['session_name' => 'default']);
        $chat = Chat::factory()->create([
            'whatsapp_session_id' => $session->id,
            'whatsapp_chat_id' => '77009876543@c.us',
            'is_group' => false,
            'ai_enabled' => false,
            'funnel_tracking_enabled' => false,
            'messages_cleared_at' => now()->subMinute(),
        ]);

        $payload = [
            'session' => 'default',
            'chatId' => '77009876543@c.us',
            'chatName' => 'Client',
            'from' => '77009876543@c.us',
            'senderPhone' => '77009876543',
            'senderName' => 'Client',
            'body' => 'Новый вопрос',
            'isGroup' => false,
            'timestamp' => now()->getTimestamp(),
            'messageId' => 'wa-msg-new-1',
            'type' => 'chat',
        ];

        $job = new ProcessWhatsappInboundJob($payload);
        $this->app->call([$job, 'handle']);

        $message = Message::query()->where('whatsapp_message_id', 'wa-msg-new-1')->first();
        $this->assertNotNull($message);
        $this->assertSame('Новый вопрос', $message->body);
        $chat->refresh();
        $this->assertNull($chat->messages_cleared_at);
    }

    public function test_lid_inbound_after_client_clear_is_accepted(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('administrator');

        $session = WhatsappSession::factory()->create(['session_name' => 'default']);
        $contact = Contact::factory()->create([
            'whatsapp_id' => '33724234223783@lid',
            'phone_number' => '',
            'name' => 'Алымжан',
        ]);
        $chat = Chat::factory()->create([
            'contact_id' => $contact->id,
            'whatsapp_session_id' => $session->id,
            'whatsapp_chat_id' => '33724234223783@lid',
            'is_group' => false,
            'ai_enabled' => false,
            'funnel_tracking_enabled' => false,
        ]);

        $this->actingAs($admin)->post(route('clients.clear', $contact))->assertOk();

        $payload = [
            'session' => 'default',
            'chatId' => '33724234223783@lid',
            'chatName' => '+7 707 226 8668',
            'from' => '33724234223783@lid',
            'senderPhone' => '33724234223783',
            'senderAuthorJid' => '',
            'senderName' => 'Алымжан',
            'body' => 'Новое сообщение после очистки',
            'isGroup' => false,
            'timestamp' => now()->getTimestamp(),
            'messageId' => 'wa-msg-lid-new-1',
            'type' => 'chat',
        ];

        $this->app->call([new ProcessWhatsappInboundJob($payload), 'handle']);

        $message = Message::query()->where('whatsapp_message_id', 'wa-msg-lid-new-1')->first();
        $this->assertNotNull($message);
        $this->assertSame('Новое сообщение после очистки', $message->body);

        $chat->refresh();
        $this->assertNull($chat->messages_cleared_at);
        $contact->refresh();
        $this->assertNotNull($contact->messages_cleared_at);
    }

    public function test_should_skip_inbound_after_clear_helper(): void
    {
        $chat = Chat::factory()->make([
            'messages_cleared_at' => now(),
        ]);

        $service = app(ChatService::class);

        $this->assertTrue($service->shouldSkipInboundAfterClear($chat, [
            'timestamp' => now()->subHour()->getTimestamp(),
        ]));

        $this->assertFalse($service->shouldSkipInboundAfterClear($chat, [
            'timestamp' => now()->addMinute()->getTimestamp(),
        ]));
    }

    public function test_prune_does_not_delete_cleared_client_chat(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('administrator');

        $session = WhatsappSession::factory()->create(['session_name' => 'default']);
        $contact = Contact::factory()->create();
        $chat = Chat::factory()->create([
            'contact_id' => $contact->id,
            'whatsapp_session_id' => $session->id,
            'whatsapp_chat_id' => '77001112233@c.us',
            'is_group' => false,
        ]);

        $this->actingAs($admin)->post(route('clients.clear', $contact))->assertOk();

        $chat->refresh();
        $this->assertNotNull($chat->messages_cleared_at);

        $this->artisan('chats:prune-ghost-whatsapp')->assertSuccessful();

        $this->assertDatabaseHas('chats', ['id' => $chat->id]);
    }

    public function test_resync_after_prune_still_blocked_when_contact_was_cleared(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('administrator');

        $session = WhatsappSession::factory()->create(['session_name' => 'default']);
        $contact = Contact::factory()->create([
            'whatsapp_id' => '33724234223783@lid',
        ]);
        $chat = Chat::factory()->create([
            'contact_id' => $contact->id,
            'whatsapp_session_id' => $session->id,
            'whatsapp_chat_id' => '33724234223783@lid',
            'is_group' => false,
            'ai_enabled' => false,
            'funnel_tracking_enabled' => false,
        ]);

        $oldTimestamp = now()->subHours(2)->getTimestamp();
        $payload = [
            'session' => 'default',
            'chatId' => '33724234223783@lid',
            'chatName' => 'Алымжан',
            'from' => '33724234223783@lid',
            'senderAuthorJid' => '33724234223783@lid',
            'body' => 'Есть?',
            'isGroup' => false,
            'timestamp' => $oldTimestamp,
            'messageId' => 'wa-msg-resync-1',
            'type' => 'chat',
        ];

        $this->app->call([new ProcessWhatsappInboundJob($payload), 'handle']);
        $this->assertNotNull(Message::query()->where('whatsapp_message_id', 'wa-msg-resync-1')->first());

        $this->actingAs($admin)->post(route('clients.clear', $contact))->assertOk();

        $this->artisan('chats:prune-ghost-whatsapp')->assertSuccessful();
        $this->assertDatabaseHas('chats', ['id' => $chat->id]);

        // Симулируем старый баг: чат удалили, но cutoff остался на контакте.
        $chat->delete();

        $resyncJob = new ProcessWhatsappInboundJob($payload);
        $this->app->call([$resyncJob, 'handle']);

        $this->assertNull(Message::query()->where('whatsapp_message_id', 'wa-msg-resync-1')->first());

        $recreated = Chat::query()
            ->where('whatsapp_chat_id', '33724234223783@lid')
            ->where('whatsapp_session_id', $session->id)
            ->first();

        $this->assertNotNull($recreated);
        $this->assertNotNull($recreated->messages_cleared_at);
        $this->assertNull($recreated->last_message_at);
    }
}
