<?php

declare(strict_types=1);

namespace Tests\Feature\Chat;

use App\Events\NewMessageReceived;
use App\Jobs\ProcessWhatsappCallRejectedJob;
use App\Jobs\SendOutboundMessageJob;
use App\Models\Chat;
use App\Models\User;
use App\Models\WhatsappSession;
use App\Services\ChatService;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class ProcessWhatsappCallRejectedJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['administrator', 'manager', 'employee'] as $r) {
            Role::findOrCreate($r);
        }
    }

    public function test_auto_reply_after_call_uses_system_user_dispatches_wa_job_and_broadcasts_new_message(): void
    {
        Bus::fake([SendOutboundMessageJob::class]);
        Event::fake([NewMessageReceived::class]);

        $session = WhatsappSession::factory()->create(['session_name' => 'test-session-call']);
        $peerJid = '77001234567@c.us';
        $chat = Chat::factory()->create([
            'whatsapp_session_id' => $session->id,
            'whatsapp_chat_id' => $peerJid,
        ]);

        $systemUser = User::query()->where('email', 'system@chatswitch.internal')->first();
        $this->assertNotNull($systemUser);

        $job = new ProcessWhatsappCallRejectedJob([
            'session' => 'test-session-call',
            'peerJid' => $peerJid,
            'fromMe' => false,
        ]);
        $job->handle(app(ChatService::class), app(TenantContext::class));

        $message = $chat->messages()->latest('id')->first();
        $this->assertNotNull($message);
        $this->assertSame('outbound', $message->direction);
        $this->assertSame((int) $systemUser->id, (int) $message->sent_by_user_id);

        Bus::assertDispatched(SendOutboundMessageJob::class, function (SendOutboundMessageJob $job) use ($message): bool {
            return $job->messageId === $message->id && $job->payloadType === 'text';
        });

        Event::assertDispatched(NewMessageReceived::class, function (NewMessageReceived $e) use ($chat, $message): bool {
            return $e->chatId === $chat->id && $e->message->id === $message->id;
        });
    }

    public function test_rate_limit_allows_only_one_send_job_within_window(): void
    {
        Bus::fake([SendOutboundMessageJob::class]);

        $session = WhatsappSession::factory()->create(['session_name' => 'test-session-rate']);
        $peerJid = '77009876543@c.us';
        Chat::factory()->create([
            'whatsapp_session_id' => $session->id,
            'whatsapp_chat_id' => $peerJid,
        ]);

        $payload = [
            'session' => 'test-session-rate',
            'peerJid' => $peerJid,
            'fromMe' => false,
        ];

        (new ProcessWhatsappCallRejectedJob($payload))->handle(app(ChatService::class), app(TenantContext::class));
        (new ProcessWhatsappCallRejectedJob($payload))->handle(app(ChatService::class), app(TenantContext::class));

        Bus::assertDispatchedTimes(SendOutboundMessageJob::class, 1);
    }
}
