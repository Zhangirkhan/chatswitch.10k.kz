<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class AiSpeechTranscriptionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['administrator', 'manager', 'employee'] as $role) {
            Role::findOrCreate($role);
        }
    }

    public function test_transcribes_uploaded_audio(): void
    {
        config()->set('services.openai.api_key', 'test-key');
        config()->set('accel.transcribe_audio', true);

        Http::fake([
            'https://api.openai.com/v1/audio/transcriptions' => Http::response([
                'text' => 'Привет, подскажи ответ клиенту',
                'duration' => 3.5,
            ]),
        ]);

        $user = User::factory()->create();
        $user->assignRole('employee');

        $response = $this->actingAs($user)->postJson(route('ai-chat.transcribe'), [
            'audio' => UploadedFile::fake()->create('dictation.webm', 100, 'audio/webm'),
        ]);

        $response->assertOk();
        $response->assertJsonPath('text', 'Привет, подскажи ответ клиенту');
    }

    public function test_returns_503_when_transcription_disabled(): void
    {
        config()->set('services.openai.api_key', '');
        config()->set('accel.transcribe_audio', true);

        $user = User::factory()->create();
        $user->assignRole('employee');

        $response = $this->actingAs($user)->postJson(route('ai-chat.transcribe'), [
            'audio' => UploadedFile::fake()->create('dictation.webm', 100, 'audio/webm'),
        ]);

        $response->assertStatus(503);
    }

    public function test_rejects_unsupported_mime_type(): void
    {
        config()->set('services.openai.api_key', 'test-key');
        config()->set('accel.transcribe_audio', true);

        $user = User::factory()->create();
        $user->assignRole('employee');

        $response = $this->actingAs($user)->postJson(route('ai-chat.transcribe'), [
            'audio' => UploadedFile::fake()->create('notes.txt', 100, 'text/plain'),
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['audio']);
    }

    public function test_requires_authentication(): void
    {
        $response = $this->postJson(route('ai-chat.transcribe'), [
            'audio' => UploadedFile::fake()->create('dictation.webm', 100, 'audio/webm'),
        ]);

        $response->assertUnauthorized();
    }

    public function test_passes_explicit_language_to_whisper(): void
    {
        config()->set('services.openai.api_key', 'test-key');
        config()->set('accel.transcribe_audio', true);
        config()->set('accel.whisper_prompt_dictation', 'Dictation prompt');

        Http::fake([
            'https://api.openai.com/v1/audio/transcriptions' => Http::response([
                'text' => 'Сәлем',
                'duration' => 2.0,
            ]),
        ]);

        $user = User::factory()->create();
        $user->assignRole('employee');

        $response = $this->actingAs($user)->postJson(route('ai-chat.transcribe'), [
            'audio' => UploadedFile::fake()->create('dictation.webm', 100, 'audio/webm'),
            'language' => 'kk',
        ]);

        $response->assertOk();

        Http::assertSent(static function (Request $request): bool {
            if (! str_contains($request->url(), '/audio/transcriptions')) {
                return false;
            }
            $body = $request->body();

            return str_contains($body, 'name="language"') && str_contains($body, 'kk');
        });
    }

    public function test_infers_language_from_chat_context(): void
    {
        config()->set('services.openai.api_key', 'test-key');
        config()->set('accel.transcribe_audio', true);
        config()->set('accel.whisper_auto_detect_language', true);

        Http::fake([
            'https://api.openai.com/v1/audio/transcriptions' => Http::response([
                'text' => 'Жауап беріңіз',
                'duration' => 2.5,
            ]),
        ]);

        $user = User::factory()->create();
        $user->assignRole('administrator');

        $session = \App\Models\WhatsappSession::factory()->create();
        $chat = \App\Models\Chat::factory()->create(['whatsapp_session_id' => $session->id]);

        \App\Models\Message::query()->create([
            'chat_id' => $chat->id,
            'whatsapp_session_id' => $session->id,
            'direction' => 'inbound',
            'type' => 'chat',
            'body' => 'Сәлеметсіз бе, қанша тұрады?',
            'ack' => 'delivered',
            'message_timestamp' => now(),
        ]);

        $response = $this->actingAs($user)->postJson(route('ai-chat.transcribe'), [
            'audio' => UploadedFile::fake()->create('dictation.webm', 100, 'audio/webm'),
            'chat_id' => $chat->id,
        ]);

        $response->assertOk();

        Http::assertSent(static function (Request $request): bool {
            if (! str_contains($request->url(), '/audio/transcriptions')) {
                return false;
            }
            $body = $request->body();

            return str_contains($body, 'name="language"') && str_contains($body, 'kk');
        });
    }
}
