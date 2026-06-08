<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Events\ChatsListNotify;
use App\Models\Chat;
use App\Models\ChatAssignment;
use App\Models\Contact;
use App\Models\Message;
use App\Models\User;
use App\Models\WhatsappSession;
use App\Services\ChatService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class ChatLeadApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['administrator', 'manager', 'employee'] as $roleName) {
            Role::findOrCreate($roleName);
        }
    }

    public function test_close_lead_sets_flags_and_returns_chat_resource(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('administrator');
        Sanctum::actingAs($admin);

        $session = WhatsappSession::factory()->create();
        $chat = Chat::factory()->create([
            'whatsapp_session_id' => $session->id,
            'is_lead_closed' => false,
        ]);

        $this->postJson("/api/v1/chats/{$chat->id}/close")
            ->assertOk()
            ->assertJsonPath('data.id', $chat->id)
            ->assertJsonPath('data.is_lead_closed', true);

        $chat->refresh();
        $this->assertTrue($chat->is_lead_closed);
        $this->assertNotNull($chat->lead_closed_at);
    }

    public function test_reopen_lead_clears_flags(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('administrator');
        Sanctum::actingAs($admin);

        $session = WhatsappSession::factory()->create();
        $chat = Chat::factory()->create([
            'whatsapp_session_id' => $session->id,
            'is_lead_closed' => true,
            'lead_closed_at' => now(),
        ]);

        $this->postJson("/api/v1/chats/{$chat->id}/reopen")
            ->assertOk()
            ->assertJsonPath('data.is_lead_closed', false);

        $chat->refresh();
        $this->assertFalse($chat->is_lead_closed);
        $this->assertNull($chat->lead_closed_at);
    }

    public function test_closed_filter_returns_only_closed_leads(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('administrator');
        Sanctum::actingAs($admin);

        $session = WhatsappSession::factory()->create();

        $open = Chat::factory()->create([
            'whatsapp_session_id' => $session->id,
            'is_lead_closed' => false,
        ]);
        $closed = Chat::factory()->create([
            'whatsapp_session_id' => $session->id,
            'is_lead_closed' => true,
            'lead_closed_at' => now(),
        ]);

        $this->seedInboundMessage($open, $session);
        $this->seedInboundMessage($closed, $session);

        $response = $this->getJson('/api/v1/chats?filter=closed')->assertOk();
        $ids = collect($response->json('data'))->pluck('id');

        $this->assertTrue($ids->contains($closed->id));
        $this->assertFalse($ids->contains($open->id));
    }

    public function test_auto_reply_filter_returns_ai_auto_chats(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('administrator');
        Sanctum::actingAs($admin);

        $session = WhatsappSession::factory()->create();

        $auto = Chat::factory()->create([
            'whatsapp_session_id' => $session->id,
            'ai_enabled' => true,
            'ai_mode' => 'auto',
            'is_group' => false,
        ]);
        $manual = Chat::factory()->create([
            'whatsapp_session_id' => $session->id,
            'ai_enabled' => true,
            'ai_mode' => 'manual',
            'is_group' => false,
        ]);

        $this->seedInboundMessage($auto, $session);
        $this->seedInboundMessage($manual, $session);

        $response = $this->getJson('/api/v1/chats?filter=auto_reply')->assertOk();
        $ids = collect($response->json('data'))->pluck('id');

        $this->assertTrue($ids->contains($auto->id));
        $this->assertFalse($ids->contains($manual->id));
    }

    public function test_store_creates_or_returns_chat_for_contact(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('administrator');
        Sanctum::actingAs($admin);

        $session = WhatsappSession::factory()->create(['desired_state' => WhatsappSession::DESIRED_ACTIVE]);
        $contact = Contact::factory()->create([
            'phone_number' => '77001112233',
            'whatsapp_id' => '77001112233@c.us',
        ]);

        $response = $this->postJson('/api/v1/chats', [
            'contact_id' => $contact->id,
            'whatsapp_session_id' => $session->id,
        ])->assertCreated();

        $chatId = (int) $response->json('data.id');
        $this->assertDatabaseHas('chats', [
            'id' => $chatId,
            'contact_id' => $contact->id,
            'whatsapp_session_id' => $session->id,
        ]);

        $this->postJson('/api/v1/chats', [
            'contact_id' => $contact->id,
            'whatsapp_session_id' => $session->id,
        ])->assertCreated()
            ->assertJsonPath('data.id', $chatId);
    }

    public function test_inbound_message_reopens_closed_lead_and_broadcasts_list_update(): void
    {
        Event::fake([ChatsListNotify::class]);

        $session = WhatsappSession::factory()->create();
        $chat = Chat::factory()->create([
            'whatsapp_session_id' => $session->id,
            'is_lead_closed' => true,
            'lead_closed_at' => now()->subHour(),
        ]);

        app(ChatService::class)->storeInboundMessage($chat, $session, [
            'body' => 'Снова пишу',
            'type' => 'chat',
            'from' => $chat->whatsapp_chat_id,
            'timestamp' => now()->timestamp,
            'messageId' => 'wa-msg-'.uniqid(),
        ]);

        $chat->refresh();
        $this->assertFalse($chat->is_lead_closed);
        $this->assertNull($chat->lead_closed_at);

        Event::assertDispatched(ChatsListNotify::class, function (ChatsListNotify $event) use ($chat): bool {
            return $event->chatId === $chat->id
                && $event->kind === 'lead_reopened'
                && ($event->extra['is_lead_closed'] ?? null) === false;
        });
    }

    public function test_mine_filter_limits_to_assigned_chats(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('administrator');
        Sanctum::actingAs($admin);

        $session = WhatsappSession::factory()->create();
        $mine = Chat::factory()->create(['whatsapp_session_id' => $session->id]);
        $other = Chat::factory()->create(['whatsapp_session_id' => $session->id]);

        ChatAssignment::create([
            'chat_id' => $mine->id,
            'user_id' => $admin->id,
            'assigned_by' => $admin->id,
        ]);

        $this->seedInboundMessage($mine, $session);
        $this->seedInboundMessage($other, $session);

        $ids = collect($this->getJson('/api/v1/chats?filter=mine')->json('data'))->pluck('id');

        $this->assertTrue($ids->contains($mine->id));
        $this->assertFalse($ids->contains($other->id));
    }

    private function seedInboundMessage(Chat $chat, WhatsappSession $session): void
    {
        Message::query()->create([
            'chat_id' => $chat->id,
            'whatsapp_session_id' => $session->id,
            'direction' => 'inbound',
            'type' => 'chat',
            'body' => 'hello',
            'message_timestamp' => now(),
        ]);
    }
}
