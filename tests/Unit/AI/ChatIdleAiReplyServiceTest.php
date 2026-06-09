<?php

declare(strict_types=1);

namespace Tests\Unit\AI;

use App\Models\Chat;
use App\Models\Message;
use App\Models\User;
use App\Models\WhatsappSession;
use App\Services\AI\ChatIdleAiReplyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ChatIdleAiReplyServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_skips_scheduling_for_thank_you(): void
    {
        $service = $this->app->make(ChatIdleAiReplyService::class);
        $company = $this->createTenantCompany(['name' => 'Co']);
        $session = WhatsappSession::factory()->create();
        $chat = Chat::factory()->create([
            'company_id' => $company->id,
            'whatsapp_session_id' => $session->id,
            'ai_enabled' => true,
        ]);
        $message = Message::create([
            'chat_id' => $chat->id,
            'whatsapp_session_id' => $session->id,
            'direction' => 'inbound',
            'type' => 'chat',
            'body' => 'Спасибо!',
            'ack' => 'delivered',
            'message_timestamp' => now(),
        ]);

        $this->assertTrue($service->shouldSkipScheduling($message));
    }

    public function test_skips_scheduling_for_empty_inbound_without_media(): void
    {
        $service = $this->app->make(ChatIdleAiReplyService::class);
        $company = $this->createTenantCompany(['name' => 'Co']);
        $session = WhatsappSession::factory()->create();
        $chat = Chat::factory()->create([
            'company_id' => $company->id,
            'whatsapp_session_id' => $session->id,
            'ai_enabled' => true,
        ]);
        $message = Message::create([
            'chat_id' => $chat->id,
            'whatsapp_session_id' => $session->id,
            'direction' => 'inbound',
            'type' => 'chat',
            'body' => '',
            'ack' => 'delivered',
            'message_timestamp' => now(),
        ]);

        $this->assertTrue($service->shouldSkipScheduling($message));
    }

    public function test_blocks_reply_when_manager_answered_after_trigger(): void
    {
        $service = $this->app->make(ChatIdleAiReplyService::class);
        $company = $this->createTenantCompany(['name' => 'Co']);
        $manager = User::factory()->create(['company_id' => $company->id]);
        $session = WhatsappSession::factory()->create();
        $chat = Chat::factory()->create([
            'company_id' => $company->id,
            'whatsapp_session_id' => $session->id,
            'ai_enabled' => true,
        ]);

        $trigger = Message::create([
            'chat_id' => $chat->id,
            'whatsapp_session_id' => $session->id,
            'direction' => 'inbound',
            'type' => 'chat',
            'body' => 'Сколько стоит?',
            'ack' => 'delivered',
            'message_timestamp' => now()->subMinutes(15),
        ]);

        Message::create([
            'chat_id' => $chat->id,
            'whatsapp_session_id' => $session->id,
            'direction' => 'outbound',
            'type' => 'chat',
            'body' => 'Сейчас уточню',
            'ack' => 'delivered',
            'sent_by_user_id' => $manager->id,
            'message_timestamp' => now()->subMinutes(12),
            'metadata' => ['ai' => ['generated' => false]],
        ]);

        $this->assertFalse($service->canExecuteReply($chat, $trigger));
    }

    public function test_allows_immediate_reply_when_client_still_waiting(): void
    {
        $service = $this->app->make(ChatIdleAiReplyService::class);
        $company = $this->createTenantCompany(['name' => 'Co']);
        $session = WhatsappSession::factory()->create();
        $chat = Chat::factory()->create([
            'company_id' => $company->id,
            'whatsapp_session_id' => $session->id,
            'ai_enabled' => true,
        ]);

        $trigger = Message::create([
            'chat_id' => $chat->id,
            'whatsapp_session_id' => $session->id,
            'direction' => 'inbound',
            'type' => 'chat',
            'body' => 'Hello, price?',
            'ack' => 'delivered',
            'message_timestamp' => now()->subMinutes(1),
        ]);

        $this->assertTrue($service->canExecuteReply($chat, $trigger));
    }
}
