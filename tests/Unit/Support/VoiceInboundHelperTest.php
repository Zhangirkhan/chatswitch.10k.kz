<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Models\Chat;
use App\Models\Message;
use App\Models\WhatsappSession;
use App\Support\VoiceInboundHelper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class VoiceInboundHelperTest extends TestCase
{
    use RefreshDatabase;

    public function test_needs_transcription_even_when_caption_in_body(): void
    {
        config()->set('services.openai.api_key', 'test-key');
        config()->set('accel.transcribe_audio', true);

        $session = WhatsappSession::factory()->create();
        $chat = Chat::factory()->create(['whatsapp_session_id' => $session->id]);
        $message = Message::create([
            'chat_id' => $chat->id,
            'whatsapp_session_id' => $session->id,
            'direction' => 'inbound',
            'type' => 'ptt',
            'body' => 'Краткая подпись',
            'ack' => 'delivered',
            'message_timestamp' => now(),
        ]);

        $this->assertTrue(VoiceInboundHelper::needsTranscriptionBeforeAi($message));
    }

    public function test_duration_skip_when_too_short(): void
    {
        config()->set('accel.transcribe_min_duration_seconds', 2);

        $message = Message::make([
            'type' => 'ptt',
            'metadata' => ['media' => ['duration' => 0.5]],
        ]);

        $result = VoiceInboundHelper::durationSkipReason($message);

        $this->assertTrue($result['skip']);
    }
}
