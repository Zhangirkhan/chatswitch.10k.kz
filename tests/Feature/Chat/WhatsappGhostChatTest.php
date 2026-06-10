<?php

declare(strict_types=1);

namespace Tests\Feature\Chat;

use App\Jobs\AnalyzeChatFunnelJob;
use App\Jobs\GenerateAiReplyJob;
use App\Jobs\ProcessWhatsappInboundJob;
use App\Jobs\RunAiFunnelOrchestratorJob;
use App\Models\Chat;
use App\Models\Company;
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

    public function test_lid_inbound_with_empty_sender_author_jid_uses_whatsapp_id_not_lid_digits_as_phone(): void
    {
        $existing = \App\Models\Contact::factory()->create([
            'whatsapp_id' => '33724234223783@lid',
            'phone_number' => '',
            'name' => 'Алымжан',
        ]);

        $contact = app(ChatService::class)->findOrCreateContact([
            'senderAuthorJid' => '',
            'from' => '33724234223783@lid',
            'chatId' => '33724234223783@lid',
            'senderPhone' => '33724234223783',
            'senderName' => 'Алымжан',
        ]);

        $this->assertSame($existing->id, $contact->id);
        $this->assertSame('33724234223783@lid', $contact->whatsapp_id);
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

    public function test_archived_feed_includes_closed_leads(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('administrator');

        $session = WhatsappSession::factory()->create();

        $closedArchived = Chat::factory()->create([
            'whatsapp_session_id' => $session->id,
            'is_archived' => true,
            'is_lead_closed' => true,
            'last_message_at' => now(),
        ]);

        Message::create([
            'chat_id' => $closedArchived->id,
            'whatsapp_session_id' => $session->id,
            'direction' => 'inbound',
            'type' => 'chat',
            'body' => 'Закрытый лид',
            'ack' => 'delivered',
            'message_timestamp' => now(),
        ]);

        $response = $this->actingAs($admin)
            ->getJson(route('chats.feed', ['archived' => 1]));

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($closedArchived->id, $ids);
    }

    public function test_inbound_contact_can_exist_in_multiple_tenants_with_same_whatsapp_id(): void
    {
        $companyA = $this->createTenantCompany(['slug' => 'tenant-a', 'name' => 'Tenant A']);
        $companyB = Company::query()->create([
            'name' => 'Tenant B',
            'slug' => 'tenant-b',
            'is_active' => true,
            'subscription_status' => 'active',
        ]);

        $whatsappId = '33724234223783@lid';

        $this->switchTenant($companyA);
        $contactA = app(ChatService::class)->findOrCreateContact([
            'chatId' => $whatsappId,
            'senderName' => 'Алымжан',
        ]);
        $this->assertSame($companyA->id, $contactA->company_id);

        $this->switchTenant($companyB);
        $sessionB = WhatsappSession::factory()->create([
            'company_id' => $companyB->id,
            'session_name' => 'wa-tenant-b',
        ]);

        Bus::fake([
            GenerateAiReplyJob::class,
            RunAiFunnelOrchestratorJob::class,
            AnalyzeChatFunnelJob::class,
        ]);

        $job = new ProcessWhatsappInboundJob([
            'session' => $sessionB->session_name,
            'companyId' => $companyB->id,
            'messageId' => 'false_'.$whatsappId.'_TESTMSG',
            'from' => $whatsappId,
            'to' => '77770188444@c.us',
            'body' => 'Здравствуйте, хочу заказать набор 6в1',
            'type' => 'chat',
            'timestamp' => now()->timestamp,
            'isGroup' => false,
            'chatId' => $whatsappId,
            'chatName' => '+7 707 226 8668',
            'senderPhone' => '33724234223783',
            'senderName' => 'Алымжан',
        ]);

        $job->handle(
            app(ChatService::class),
            app(\App\Services\AI\ChatDepartmentRoutingService::class),
            app(\App\Services\AI\ChatOffHoursReplyService::class),
            app(\App\Services\AI\AutomatedPeerReplyGuard::class),
            app(\App\Tenancy\TenantContext::class),
            app(\App\Services\AI\InboundAiDispatchService::class),
        );

        $contactB = \App\Models\Contact::query()->where('whatsapp_id', $whatsappId)->first();
        $this->assertNotNull($contactB);
        $this->assertSame($companyB->id, $contactB->company_id);
        $this->assertNotSame($contactA->id, $contactB->id);

        $this->assertDatabaseHas('messages', [
            'body' => 'Здравствуйте, хочу заказать набор 6в1',
            'direction' => 'inbound',
        ]);
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

    public function test_feed_excludes_ai_only_ghost_chats(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('administrator');

        $session = WhatsappSession::factory()->create();

        $ghost = Chat::factory()->create([
            'whatsapp_session_id' => $session->id,
            'whatsapp_chat_id' => '247634962960547@lid',
            'chat_name' => 'Контакт WhatsApp',
            'last_message_at' => now(),
        ]);

        Message::create([
            'chat_id' => $ghost->id,
            'whatsapp_session_id' => $session->id,
            'direction' => 'outbound',
            'type' => 'chat',
            'body' => 'Не увидели текст сообщения. Напишите, пожалуйста, ваш вопрос — подскажем.',
            'ack' => 'delivered',
            'sent_by_user_id' => $admin->id,
            'message_timestamp' => now(),
            'metadata' => ['ai' => ['generated' => true]],
        ]);

        $real = Chat::factory()->create([
            'whatsapp_session_id' => $session->id,
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

        $response = $this->actingAs($admin)->getJson(route('chats.feed'));

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($real->id, $ids);
        $this->assertNotContains($ghost->id, $ids);
    }

    public function test_prune_command_removes_ai_only_ghost_chats(): void
    {
        $session = WhatsappSession::factory()->create();

        $ghost = Chat::factory()->create([
            'whatsapp_session_id' => $session->id,
            'whatsapp_chat_id' => '247634962960547@lid',
            'chat_name' => 'Контакт WhatsApp',
            'last_message_at' => now(),
        ]);

        Message::create([
            'chat_id' => $ghost->id,
            'whatsapp_session_id' => $session->id,
            'direction' => 'outbound',
            'type' => 'chat',
            'body' => 'Сообщение снова пришло без текста. Отправьте, пожалуйста, текст вопроса.',
            'ack' => 'delivered',
            'sent_by_user_id' => User::factory()->create()->id,
            'message_timestamp' => now(),
            'metadata' => ['ai' => ['generated' => true]],
        ]);

        $this->assertFalse(app(ChatService::class)->chatHasRealConversation($ghost));

        $this->artisan('chats:prune-ghost-whatsapp')
            ->assertSuccessful();

        $this->assertDatabaseMissing('chats', ['id' => $ghost->id]);
    }
}
