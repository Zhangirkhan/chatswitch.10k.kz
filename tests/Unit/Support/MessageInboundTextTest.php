<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Models\Chat;
use App\Models\Message;
use App\Models\MessageTranscript;
use App\Models\WhatsappSession;
use App\Support\MessageInboundText;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class MessageInboundTextTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_body_when_present(): void
    {
        $message = Message::make([
            'direction' => 'inbound',
            'type' => 'chat',
            'body' => 'Привет',
        ]);

        $this->assertSame('Привет', MessageInboundText::forMessage($message));
    }

    public function test_returns_transcript_when_body_empty(): void
    {
        $session = WhatsappSession::factory()->create();
        $chat = Chat::factory()->create(['whatsapp_session_id' => $session->id]);
        $message = Message::create([
            'chat_id' => $chat->id,
            'whatsapp_session_id' => $session->id,
            'direction' => 'inbound',
            'type' => 'ptt',
            'body' => '',
            'ack' => 'delivered',
            'message_timestamp' => now(),
        ]);

        MessageTranscript::create([
            'message_id' => $message->id,
            'kind' => MessageTranscript::KIND_AUDIO,
            'status' => MessageTranscript::STATUS_COMPLETED,
            'text' => 'Хочу заказать окна',
            'model' => 'whisper-1',
        ]);

        $message->refresh();

        $this->assertSame('Хочу заказать окна', MessageInboundText::forMessage($message));
    }

    public function test_voice_prefers_transcript_over_caption(): void
    {
        $message = Message::make([
            'direction' => 'inbound',
            'type' => 'ptt',
            'body' => 'Подпись к аудио',
        ]);
        $message->setRelation('transcript', new MessageTranscript([
            'status' => MessageTranscript::STATUS_COMPLETED,
            'text' => 'Содержание голосового',
        ]));

        $this->assertSame('Содержание голосового', MessageInboundText::forMessage($message));
    }

    public function test_voice_prefix_when_requested(): void
    {
        $session = WhatsappSession::factory()->create();
        $chat = Chat::factory()->create(['whatsapp_session_id' => $session->id]);
        $message = Message::create([
            'chat_id' => $chat->id,
            'whatsapp_session_id' => $session->id,
            'direction' => 'inbound',
            'type' => 'ptt',
            'body' => '',
            'ack' => 'delivered',
            'message_timestamp' => now(),
        ]);

        MessageTranscript::create([
            'message_id' => $message->id,
            'kind' => MessageTranscript::KIND_AUDIO,
            'status' => MessageTranscript::STATUS_COMPLETED,
            'text' => 'Текст голосового',
            'model' => 'whisper-1',
        ]);

        $message->refresh();

        $this->assertSame(
            '[голосовое] Текст голосового',
            MessageInboundText::forMessage($message, voicePrefixWhenFromTranscript: true),
        );
    }
}
