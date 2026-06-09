<?php

declare(strict_types=1);

namespace Tests\Feature\Webhook;

use App\Jobs\AnalyzeChatFunnelJob;
use App\Jobs\GenerateAiReplyJob;
use App\Jobs\ProcessWhatsappInboundJob;
use App\Jobs\RunAiFunnelOrchestratorJob;
use App\Models\Chat;
use App\Models\WhatsappSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class ReceiveMessageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.whatsapp.webhook_secret', 'test-secret');
        foreach (['administrator', 'manager', 'employee'] as $r) {
            Role::findOrCreate($r);
        }
    }

    public function test_webhook_rejects_request_without_signature(): void
    {
        $response = $this->postJson('/api/whatsapp/webhook', [
            'event' => 'message_received',
            'data' => ['session' => 'default'],
        ]);

        $response->assertStatus(401);
    }

    public function test_webhook_rejects_invalid_signature(): void
    {
        $body = json_encode(['event' => 'message_received', 'data' => ['session' => 'default']]);

        $response = $this->call(
            'POST',
            '/api/whatsapp/webhook',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_WEBHOOK_SIGNATURE' => 'invalid'],
            $body,
        );

        $this->assertSame(401, $response->status());
    }

    public function test_valid_webhook_dispatches_inbound_job(): void
    {
        WhatsappSession::factory()->create(['session_name' => 'default']);
        Bus::fake();

        $payload = [
            'event' => 'message_received',
            'data' => [
                'session' => 'default',
                'chatId' => '77771234567@c.us',
                'from' => '77771234567@c.us',
                'body' => 'Hello',
                'timestamp' => time(),
            ],
        ];

        $body = json_encode($payload);
        $signature = hash_hmac('sha256', $body, 'test-secret');

        $response = $this->call(
            'POST',
            '/api/whatsapp/webhook',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_WEBHOOK_SIGNATURE' => $signature],
            $body,
        );

        $response->assertOk();
        Bus::assertDispatched(ProcessWhatsappInboundJob::class);
    }

    public function test_inbound_job_creates_chat_and_message(): void
    {
        $session = WhatsappSession::factory()->create(['session_name' => 'default']);

        Bus::fake([
            GenerateAiReplyJob::class,
            RunAiFunnelOrchestratorJob::class,
            AnalyzeChatFunnelJob::class,
        ]);

        config(['funnel.department_routing.enabled' => false]);

        $data = [
            'session' => 'default',
            'chatId' => '77771234567@c.us',
            'chatName' => 'Client',
            'from' => '77771234567@c.us',
            'senderPhone' => '77771234567',
            'senderName' => 'Client',
            'body' => 'Hello',
            'isGroup' => false,
            'timestamp' => time(),
            'messageId' => 'wa-msg-1',
        ];

        $job = new ProcessWhatsappInboundJob($data);
        $this->app->call([$job, 'handle']);

        $chat = Chat::where('whatsapp_chat_id', '77771234567@c.us')
            ->where('whatsapp_session_id', $session->id)
            ->first();

        $this->assertNotNull($chat, 'Chat should have been created.');
        $this->assertFalse($chat->ai_enabled);
        $this->assertSame(1, $chat->unread_count);
        $this->assertSame(1, $chat->messages()->count());
    }

    public function test_inbound_job_does_not_dispatch_ai_jobs_when_ai_disabled_on_new_chat(): void
    {
        WhatsappSession::factory()->create(['session_name' => 'default']);

        Bus::fake([
            GenerateAiReplyJob::class,
            RunAiFunnelOrchestratorJob::class,
            AnalyzeChatFunnelJob::class,
        ]);

        config(['funnel.department_routing.enabled' => false]);

        $data = [
            'session' => 'default',
            'chatId' => '77779876543@c.us',
            'chatName' => 'New Client',
            'from' => '77779876543@c.us',
            'senderPhone' => '77779876543',
            'senderName' => 'New Client',
            'body' => 'Здравствуйте',
            'isGroup' => false,
            'timestamp' => time(),
            'messageId' => 'wa-msg-new-client',
        ];

        $job = new ProcessWhatsappInboundJob($data);
        $this->app->call([$job, 'handle']);

        $chat = Chat::query()->where('whatsapp_chat_id', '77779876543@c.us')->first();
        $this->assertNotNull($chat);
        $this->assertFalse($chat->ai_enabled);

        Bus::assertNotDispatched(GenerateAiReplyJob::class);
        Bus::assertNotDispatched(RunAiFunnelOrchestratorJob::class);
        Bus::assertNotDispatched(AnalyzeChatFunnelJob::class);
    }
}
