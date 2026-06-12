<?php

declare(strict_types=1);

namespace Tests\Unit\AI;

use App\Models\Chat;
use App\Models\Message;
use App\Models\User;
use App\Models\WhatsappSession;
use App\Services\AI\ChatClientLanguageService;
use App\Support\MessageLanguageHeuristics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ChatClientLanguageServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolves_outgoing_target_from_client_language(): void
    {
        $service = $this->app->make(ChatClientLanguageService::class);
        $company = $this->createTenantCompany(['name' => 'Co']);
        $session = WhatsappSession::factory()->create();
        $chat = Chat::factory()->create([
            'company_id' => $company->id,
            'whatsapp_session_id' => $session->id,
        ]);

        Message::create([
            'chat_id' => $chat->id,
            'whatsapp_session_id' => $session->id,
            'direction' => 'inbound',
            'type' => 'chat',
            'body' => 'Сәлем, қанша тұрады?',
            'ack' => 'delivered',
            'message_timestamp' => now(),
        ]);

        $target = $service->resolveOutgoingTarget($chat, 'Здравствуйте, цена 5000 тенге.');

        $this->assertSame(MessageLanguageHeuristics::LANG_KK, $target);
    }

    public function test_returns_null_when_draft_already_matches_client_language(): void
    {
        $service = $this->app->make(ChatClientLanguageService::class);
        $company = $this->createTenantCompany(['name' => 'Co']);
        $session = WhatsappSession::factory()->create();
        $chat = Chat::factory()->create([
            'company_id' => $company->id,
            'whatsapp_session_id' => $session->id,
        ]);

        Message::create([
            'chat_id' => $chat->id,
            'whatsapp_session_id' => $session->id,
            'direction' => 'inbound',
            'type' => 'chat',
            'body' => 'Здравствуйте, сколько стоит?',
            'ack' => 'delivered',
            'message_timestamp' => now(),
        ]);

        $target = $service->resolveOutgoingTarget($chat, 'Добрый день, стоимость 5000 тенге.');

        $this->assertNull($target);
    }

    public function test_resolves_outgoing_target_for_plain_cyrillic_kazakh_client(): void
    {
        $service = $this->app->make(ChatClientLanguageService::class);
        $company = $this->createTenantCompany(['name' => 'Co']);
        $session = WhatsappSession::factory()->create();
        $chat = Chat::factory()->create([
            'company_id' => $company->id,
            'whatsapp_session_id' => $session->id,
        ]);

        Message::create([
            'chat_id' => $chat->id,
            'whatsapp_session_id' => $session->id,
            'direction' => 'inbound',
            'type' => 'chat',
            'body' => 'салеметсизбе',
            'ack' => 'delivered',
            'message_timestamp' => now(),
        ]);

        $target = $service->resolveOutgoingTarget($chat, 'Здравствуйте, цена 5000 тенге.');

        $this->assertSame(MessageLanguageHeuristics::LANG_KK, $target);
    }
}
