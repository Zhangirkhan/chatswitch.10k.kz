<?php

declare(strict_types=1);

namespace Tests\Feature\Voice;

use App\Jobs\GenerateAiReplyJob;
use App\Jobs\TranscribeAudioJob;
use App\Models\Chat;
use App\Models\Message;
use App\Models\MessageMedia;
use App\Models\MessageTranscript;
use App\Models\WhatsappSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class TranscribeAudioJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake();
    }

    public function test_transcribes_audio_and_dispatches_ai_reply(): void
    {
        Bus::fake([GenerateAiReplyJob::class]);

        config()->set('services.openai.api_key', 'test-key');
        config()->set('accel.transcribe_audio', true);

        Http::fake([
            'https://api.openai.com/v1/audio/transcriptions' => Http::response([
                'text' => 'Сәлеметсіз бе, бағасын білгім келеді',
            ]),
        ]);

        Storage::fake('local');
        $diskPath = 'whatsapp-media/test/voice.ogg';
        Storage::disk('local')->put($diskPath, 'fake-audio-content');

        $session = WhatsappSession::factory()->create();
        $chat = Chat::factory()->create([
            'whatsapp_session_id' => $session->id,
            'ai_enabled' => true,
            'funnel_tracking_enabled' => false,
        ]);

        $message = Message::create([
            'chat_id' => $chat->id,
            'whatsapp_session_id' => $session->id,
            'direction' => 'inbound',
            'type' => 'ptt',
            'body' => '',
            'ack' => 'delivered',
            'message_timestamp' => now(),
        ]);

        MessageMedia::create([
            'message_id' => $message->id,
            'mime_type' => 'audio/ogg',
            'filename' => 'voice.ogg',
            'disk_path' => $diskPath,
            'file_size' => 100,
        ]);

        $job = new TranscribeAudioJob($message->id);
        $this->app->call([$job, 'handle']);

        Http::assertSent(function (Request $request): bool {
            if (! str_contains($request->url(), '/audio/transcriptions')) {
                return false;
            }
            $body = $request->body();

            return $body !== '' && str_contains($body, 'name="language"')
                && str_contains($body, 'kk')
                && str_contains($body, 'name="prompt"');
        });

        $message->refresh();
        $this->assertSame('Сәлеметсіз бе, бағасын білгім келеді', $message->body);
        $this->assertDatabaseHas('message_transcripts', [
            'message_id' => $message->id,
            'kind' => MessageTranscript::KIND_AUDIO,
            'status' => MessageTranscript::STATUS_COMPLETED,
            'text' => 'Сәлеметсіз бе, бағасын білгім келеді',
        ]);

        Bus::assertDispatched(GenerateAiReplyJob::class, function (GenerateAiReplyJob $job) use ($chat, $message): bool {
            return $job->chatId === $chat->id && $job->triggerMessageId === $message->id;
        });
    }

    public function test_skips_when_transcript_already_exists(): void
    {
        Bus::fake([GenerateAiReplyJob::class]);
        Http::fake();
        config()->set('services.openai.api_key', 'test-key');
        config()->set('accel.transcribe_audio', true);

        $session = WhatsappSession::factory()->create();
        $chat = Chat::factory()->create(['whatsapp_session_id' => $session->id]);
        $message = Message::create([
            'chat_id' => $chat->id,
            'whatsapp_session_id' => $session->id,
            'direction' => 'inbound',
            'type' => 'ptt',
            'body' => 'Уже есть текст',
            'ack' => 'delivered',
            'message_timestamp' => now(),
        ]);

        MessageTranscript::create([
            'message_id' => $message->id,
            'kind' => MessageTranscript::KIND_AUDIO,
            'status' => MessageTranscript::STATUS_COMPLETED,
            'text' => 'Уже есть текст',
            'model' => 'whisper-1',
        ]);

        $job = new TranscribeAudioJob($message->id);
        $this->app->call([$job, 'handle']);

        Http::assertNothingSent();
    }
}
