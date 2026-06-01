<?php

declare(strict_types=1);

namespace Tests\Feature\Voice;

use App\Jobs\GenerateAiReplyJob;
use App\Jobs\ProcessWhatsappInboundJob;
use App\Models\Chat;
use App\Models\SystemSetting;
use App\Models\WhatsappSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class VoiceInboundAiDispatchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('administrator');
        config()->set('services.openai.api_key', 'test-key');
        config()->set('accel.transcribe_audio', true);
        SystemSetting::query()->updateOrCreate(
            ['key' => 'module_funnels'],
            ['value' => 'off'],
        );
    }

    public function test_inbound_voice_does_not_dispatch_ai_reply_immediately(): void
    {
        Bus::fake([GenerateAiReplyJob::class]);

        $session = WhatsappSession::factory()->create(['session_name' => 'wa-voice']);
        $chat = Chat::factory()->create([
            'whatsapp_session_id' => $session->id,
            'whatsapp_chat_id' => '77009998877@c.us',
            'ai_enabled' => true,
            'funnel_tracking_enabled' => false,
        ]);

        $job = new ProcessWhatsappInboundJob([
            'session' => $session->session_name,
            'chatId' => $chat->whatsapp_chat_id,
            'chatName' => $chat->chat_name,
            'from' => '77009998877@c.us',
            'senderPhone' => '77009998877',
            'senderName' => 'Client',
            'body' => '',
            'type' => 'ptt',
            'messageId' => 'wa-voice-ptt-1',
            'timestamp' => now()->getTimestamp(),
            'isGroup' => false,
        ]);

        $this->app->call([$job, 'handle']);

        Bus::assertNotDispatched(GenerateAiReplyJob::class);
    }

    public function test_inbound_text_still_dispatches_ai_reply(): void
    {
        Bus::fake([GenerateAiReplyJob::class]);

        $session = WhatsappSession::factory()->create(['session_name' => 'wa-text']);
        $chat = Chat::factory()->create([
            'whatsapp_session_id' => $session->id,
            'whatsapp_chat_id' => '77008887766@c.us',
            'ai_enabled' => true,
            'funnel_tracking_enabled' => false,
        ]);

        $job = new ProcessWhatsappInboundJob([
            'session' => $session->session_name,
            'chatId' => $chat->whatsapp_chat_id,
            'chatName' => $chat->chat_name,
            'from' => '77008887766@c.us',
            'senderPhone' => '77008887766',
            'senderName' => 'Client',
            'body' => 'Сколько стоит?',
            'type' => 'chat',
            'messageId' => 'wa-text-1',
            'timestamp' => now()->getTimestamp(),
            'isGroup' => false,
        ]);

        $this->app->call([$job, 'handle']);

        Bus::assertDispatched(GenerateAiReplyJob::class);
    }
}
