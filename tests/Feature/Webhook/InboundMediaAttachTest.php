<?php

declare(strict_types=1);

namespace Tests\Feature\Webhook;

use App\Jobs\TranscribeAudioJob;
use App\Models\Chat;
use App\Models\Message;
use App\Models\MessageMedia;
use App\Models\WhatsappSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class InboundMediaAttachTest extends TestCase
{
    use RefreshDatabase;

    public function test_rejects_without_bearer_token(): void
    {
        config()->set('services.whatsapp.service_token', 'secret-token');

        $this->post('/api/whatsapp/inbound-media', [])->assertStatus(401);
    }

    public function test_returns_404_retry_when_message_not_found_yet(): void
    {
        config()->set('services.whatsapp.service_token', 'secret-token');
        WhatsappSession::factory()->create(['session_name' => 'wa-test']);

        $response = $this->call(
            'POST',
            '/api/whatsapp/inbound-media',
            [
                'session' => 'wa-test',
                'messageId' => 'true_77001234567@c.us_DEADBEEF',
                'mimetype' => 'audio/ogg',
            ],
            [],
            ['file' => UploadedFile::fake()->create('v.ogg', 50, 'audio/ogg')],
            ['HTTP_AUTHORIZATION' => 'Bearer secret-token'],
        );

        $response->assertStatus(404);
        $response->assertJson(['status' => 'message_not_found', 'retry' => true]);
    }

    public function test_stores_media_and_returns_ok(): void
    {
        Bus::fake([TranscribeAudioJob::class]);

        Role::findOrCreate('administrator');
        config()->set('services.whatsapp.service_token', 'secret-token');
        $session = WhatsappSession::factory()->create(['session_name' => 'wa-test']);
        $chat = Chat::factory()->create(['whatsapp_session_id' => $session->id]);
        $waId = 'true_77001234567@c.us_ATTACH01';

        $message = Message::create([
            'chat_id' => $chat->id,
            'whatsapp_session_id' => $session->id,
            'whatsapp_message_id' => $waId,
            'direction' => 'inbound',
            'type' => 'ptt',
            'body' => '',
            'ack' => 'delivered',
            'message_timestamp' => now(),
        ]);

        $response = $this->call(
            'POST',
            '/api/whatsapp/inbound-media',
            [
                'session' => 'wa-test',
                'messageId' => $waId,
                'mimetype' => 'audio/ogg',
            ],
            [],
            ['file' => UploadedFile::fake()->create('v.ogg', 80, 'audio/ogg')],
            ['HTTP_AUTHORIZATION' => 'Bearer secret-token'],
        );

        $response->assertOk();
        $this->assertSame(1, MessageMedia::query()->where('message_id', $message->id)->count());
    }

    public function test_dispatches_transcribe_job_for_voice_media(): void
    {
        Bus::fake([TranscribeAudioJob::class]);

        Role::findOrCreate('administrator');
        config()->set('services.whatsapp.service_token', 'secret-token');
        config()->set('services.openai.api_key', 'test-key');
        config()->set('accel.transcribe_audio', true);

        $session = WhatsappSession::factory()->create(['session_name' => 'wa-test']);
        $chat = Chat::factory()->create(['whatsapp_session_id' => $session->id]);
        $waId = 'true_77001234567@c.us_VOICE01';

        Message::create([
            'chat_id' => $chat->id,
            'whatsapp_session_id' => $session->id,
            'whatsapp_message_id' => $waId,
            'direction' => 'inbound',
            'type' => 'ptt',
            'body' => '',
            'ack' => 'delivered',
            'message_timestamp' => now(),
        ]);

        $response = $this->call(
            'POST',
            '/api/whatsapp/inbound-media',
            [
                'session' => 'wa-test',
                'messageId' => $waId,
                'mimetype' => 'audio/ogg',
            ],
            [],
            ['file' => UploadedFile::fake()->create('v.ogg', 80, 'audio/ogg')],
            ['HTTP_AUTHORIZATION' => 'Bearer secret-token'],
        );

        $response->assertOk();
        Bus::assertDispatched(TranscribeAudioJob::class);
    }
}
