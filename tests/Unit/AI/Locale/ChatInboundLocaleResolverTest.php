<?php

declare(strict_types=1);

namespace Tests\Unit\AI\Locale;

use App\Models\Chat;
use App\Models\Message;
use App\Models\WhatsappSession;
use App\Services\AI\Locale\ChatInboundLocaleResolver;
use App\Services\AI\Locale\KazakhstanLocaleProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ChatInboundLocaleResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_prefers_kazakh_from_recent_inbound_messages_over_short_russian_clarification(): void
    {
        $session = WhatsappSession::factory()->create();
        $chat = Chat::factory()->create(['whatsapp_session_id' => $session->id]);

        Message::create([
            'chat_id' => $chat->id,
            'whatsapp_session_id' => $session->id,
            'direction' => 'inbound',
            'type' => 'chat',
            'body' => 'Ассалаумағалайкум қанша тұрады?',
            'message_timestamp' => now()->subMinute(),
        ]);

        $trigger = Message::create([
            'chat_id' => $chat->id,
            'whatsapp_session_id' => $session->id,
            'direction' => 'inbound',
            'type' => 'chat',
            'body' => '6 инструмент',
            'message_timestamp' => now(),
        ]);

        $profile = app(ChatInboundLocaleResolver::class)->resolve($chat, $trigger);

        $this->assertGreaterThan($profile->ruPct, $profile->kkPct);
        $this->assertContains($profile->dominant, [
            KazakhstanLocaleProfile::DOMINANT_KK,
            KazakhstanLocaleProfile::DOMINANT_MIXED,
        ]);
    }

    public function test_detects_plain_cyrillic_kazakh_without_special_letters(): void
    {
        $detector = app(\App\Services\AI\Locale\KazakhstanLocaleDetector::class);
        $profile = $detector->detect('Ассалаумагалайкум канша турады?');

        $this->assertSame(KazakhstanLocaleProfile::DOMINANT_KK, $profile->dominant);
        $this->assertGreaterThan($profile->ruPct, $profile->kkPct);
    }
}
