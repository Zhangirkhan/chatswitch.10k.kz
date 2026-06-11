<?php

declare(strict_types=1);

namespace Tests\Unit\Services\AI;

use App\Models\Chat;
use App\Models\Message;
use App\Models\WhatsappSession;
use App\Services\AI\WhisperTranscriptionOptionsResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class WhisperTranscriptionOptionsResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_defaults_to_auto_without_chat_context(): void
    {
        config()->set('services.openai.whisper_language', '');
        config()->set('accel.whisper_default_language', 'auto');
        config()->set('accel.whisper_voice_fallback_language', 'auto');

        $resolver = app(WhisperTranscriptionOptionsResolver::class);
        $message = Message::make(['type' => 'ptt', 'direction' => 'inbound']);

        $options = $resolver->resolve($message);

        $this->assertNull($options['language']);
        $this->assertStringContainsString('русский', mb_strtolower($options['prompt']));
    }

    public function test_uses_russian_for_russian_text_before_voice_message(): void
    {
        config()->set('services.openai.whisper_language', '');
        config()->set('accel.whisper_default_language', 'auto');
        config()->set('accel.whisper_auto_detect_language', true);
        config()->set('accel.whisper_voice_fallback_language', 'auto');

        $session = WhatsappSession::factory()->create();
        $chat = Chat::factory()->create(['whatsapp_session_id' => $session->id]);

        Message::create([
            'chat_id' => $chat->id,
            'whatsapp_session_id' => $session->id,
            'direction' => 'inbound',
            'type' => 'chat',
            'body' => 'Здравствуйте, сколько стоит доставка?',
            'ack' => 'delivered',
            'message_timestamp' => now()->subMinute(),
        ]);

        $voice = Message::create([
            'chat_id' => $chat->id,
            'whatsapp_session_id' => $session->id,
            'direction' => 'inbound',
            'type' => 'ptt',
            'body' => '',
            'ack' => 'delivered',
            'message_timestamp' => now(),
        ]);

        $options = app(WhisperTranscriptionOptionsResolver::class)->resolve($voice);

        $this->assertSame('ru', $options['language']);
    }

    public function test_detects_weak_transcript_for_kazakh_retry(): void
    {
        $this->assertTrue(WhisperTranscriptionOptionsResolver::shouldRetryWithKazakh('Захат нищи', null));
        $this->assertFalse(WhisperTranscriptionOptionsResolver::shouldRetryWithKazakh('Сағат неше', 'kk'));
        $this->assertFalse(WhisperTranscriptionOptionsResolver::shouldRetryWithKazakh('Сколько стоит доставка?', 'ru'));
    }

    public function test_detects_kazakh_prompt_echo_for_russian_retry(): void
    {
        $this->assertTrue(WhisperTranscriptionOptionsResolver::looksLikeKazakhPromptEcho('Қазақша сөздерді.'));
        $this->assertTrue(WhisperTranscriptionOptionsResolver::shouldRetryWithRussian('Қазақша сөздерді.', 'kk'));
        $this->assertFalse(WhisperTranscriptionOptionsResolver::shouldRetryWithRussian('Сағат неше', 'kk'));
    }

    public function test_detects_prompt_echo(): void
    {
        $this->assertTrue(WhisperTranscriptionOptionsResolver::looksLikePromptEcho(
            'Казахстан, тенге, доставка, заказ, цена, услуга.',
            'Русский язык. Казахстан, тенге, доставка, заказ, цена, услуга.',
        ));
    }

    public function test_uses_russian_for_clearly_russian_text_message(): void
    {
        config()->set('services.openai.whisper_language', '');
        config()->set('accel.whisper_default_language', 'auto');
        config()->set('accel.whisper_auto_detect_language', true);

        $session = WhatsappSession::factory()->create();
        $chat = Chat::factory()->create(['whatsapp_session_id' => $session->id]);

        Message::create([
            'chat_id' => $chat->id,
            'whatsapp_session_id' => $session->id,
            'direction' => 'inbound',
            'type' => 'chat',
            'body' => 'Здравствуйте, сколько стоит доставка?',
            'ack' => 'delivered',
            'message_timestamp' => now()->subMinute(),
        ]);

        $text = Message::create([
            'chat_id' => $chat->id,
            'whatsapp_session_id' => $session->id,
            'direction' => 'inbound',
            'type' => 'chat',
            'body' => 'Нужна консультация по цене',
            'ack' => 'delivered',
            'message_timestamp' => now(),
        ]);

        $options = app(WhisperTranscriptionOptionsResolver::class)->resolve($text);

        $this->assertSame('ru', $options['language']);
    }

    public function test_uses_kazakh_for_kazakh_chat_context(): void
    {
        config()->set('services.openai.whisper_language', '');
        config()->set('accel.whisper_default_language', 'auto');
        config()->set('accel.whisper_auto_detect_language', true);
        config()->set('accel.whisper_voice_fallback_language', 'auto');

        $session = WhatsappSession::factory()->create();
        $chat = Chat::factory()->create(['whatsapp_session_id' => $session->id]);

        Message::create([
            'chat_id' => $chat->id,
            'whatsapp_session_id' => $session->id,
            'direction' => 'inbound',
            'type' => 'chat',
            'body' => 'Сәлеметсіз бе, қанша тұрады жеткізу?',
            'ack' => 'delivered',
            'message_timestamp' => now()->subMinute(),
        ]);

        $voice = Message::create([
            'chat_id' => $chat->id,
            'whatsapp_session_id' => $session->id,
            'direction' => 'inbound',
            'type' => 'ptt',
            'body' => '',
            'ack' => 'delivered',
            'message_timestamp' => now(),
        ]);

        $options = app(WhisperTranscriptionOptionsResolver::class)->resolve($voice);

        $this->assertSame('kk', $options['language']);
    }

    public function test_voice_switches_to_russian_after_russian_text_even_if_kazakh_before(): void
    {
        config()->set('services.openai.whisper_language', '');
        config()->set('accel.whisper_default_language', 'auto');
        config()->set('accel.whisper_voice_fallback_language', 'auto');

        $session = WhatsappSession::factory()->create();
        $chat = Chat::factory()->create(['whatsapp_session_id' => $session->id]);

        Message::create([
            'chat_id' => $chat->id,
            'whatsapp_session_id' => $session->id,
            'direction' => 'inbound',
            'type' => 'chat',
            'body' => 'Сәлеметсіз бе, қанша тұрады?',
            'ack' => 'delivered',
            'message_timestamp' => now()->subMinutes(2),
        ]);

        Message::create([
            'chat_id' => $chat->id,
            'whatsapp_session_id' => $session->id,
            'direction' => 'inbound',
            'type' => 'chat',
            'body' => 'Здравствуйте, сколько стоит доставка?',
            'ack' => 'delivered',
            'message_timestamp' => now()->subMinute(),
        ]);

        $voice = Message::create([
            'chat_id' => $chat->id,
            'whatsapp_session_id' => $session->id,
            'direction' => 'inbound',
            'type' => 'ptt',
            'body' => '',
            'ack' => 'delivered',
            'message_timestamp' => now(),
        ]);

        $options = app(WhisperTranscriptionOptionsResolver::class)->resolve($voice);

        $this->assertSame('ru', $options['language']);
    }

    public function test_voice_without_text_context_uses_auto_not_kazakh_voice_history(): void
    {
        config()->set('services.openai.whisper_language', '');
        config()->set('accel.whisper_default_language', 'auto');
        config()->set('accel.whisper_voice_fallback_language', 'auto');

        $session = WhatsappSession::factory()->create();
        $chat = Chat::factory()->create(['whatsapp_session_id' => $session->id]);

        Message::create([
            'chat_id' => $chat->id,
            'whatsapp_session_id' => $session->id,
            'direction' => 'inbound',
            'type' => 'ptt',
            'body' => 'Сағат неше',
            'ack' => 'delivered',
            'message_timestamp' => now()->subMinute(),
        ]);

        $voice = Message::create([
            'chat_id' => $chat->id,
            'whatsapp_session_id' => $session->id,
            'direction' => 'inbound',
            'type' => 'ptt',
            'body' => '',
            'ack' => 'delivered',
            'message_timestamp' => now(),
        ]);

        $options = app(WhisperTranscriptionOptionsResolver::class)->resolve($voice);

        $this->assertNull($options['language']);
    }

    public function test_explicit_env_overrides_detection(): void
    {
        config()->set('services.openai.whisper_language', 'kk');

        $resolver = app(WhisperTranscriptionOptionsResolver::class);
        $options = $resolver->resolve(Message::make(['type' => 'ptt']));

        $this->assertSame('kk', $options['language']);
    }

    public function test_resolve_for_dictation_uses_locale_and_prompt(): void
    {
        config()->set('accel.whisper_prompt_dictation', 'Operator dictation');

        $options = app(WhisperTranscriptionOptionsResolver::class)->resolveForDictation(null, 'ru');

        $this->assertSame('ru', $options['language']);
        $this->assertStringContainsString('Operator dictation', $options['prompt']);
    }
}
