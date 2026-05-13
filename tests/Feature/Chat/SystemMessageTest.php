<?php

declare(strict_types=1);

namespace Tests\Feature\Chat;

use App\Jobs\SendOutboundMessageJob;
use App\Models\Chat;
use App\Models\Message;
use App\Services\ChatService;
use App\Services\WhatsappService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class SystemMessageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['administrator', 'manager', 'employee'] as $r) {
            Role::findOrCreate($r);
        }
    }

    public function test_log_system_message_stores_system_direction_without_session(): void
    {
        $chat = Chat::factory()->create();
        $message = app(ChatService::class)->logSystemMessage($chat, 'Системное событие для проверки.');

        $message->refresh();

        $this->assertSame('system', $message->direction);
        $this->assertNull($message->whatsapp_session_id);
        $this->assertNull($message->whatsapp_message_id);
        $this->assertSame('Системное событие для проверки.', $message->body);
    }

    public function test_send_outbound_job_does_not_touch_whatsapp_for_system_message(): void
    {
        Http::fake();

        $chat = Chat::factory()->create();
        $message = Message::create([
            'chat_id' => $chat->id,
            'whatsapp_session_id' => null,
            'direction' => 'system',
            'type' => 'chat',
            'body' => 'Только внутри операторского UI',
            'ack' => 'read',
            'message_timestamp' => now(),
        ]);

        $job = new SendOutboundMessageJob($message->id, 'text', ['body' => 'Не должно уйти']);
        $job->handle(app(WhatsappService::class));

        Http::assertNothingSent();

        $message->refresh();
        $this->assertSame('system', $message->direction);
        $this->assertNull($message->whatsapp_message_id);
    }

    public function test_log_system_message_does_not_dispatch_send_outbound_job(): void
    {
        Bus::fake([SendOutboundMessageJob::class]);

        $chat = Chat::factory()->create();
        app(ChatService::class)->logSystemMessage($chat, 'Внутренняя запись');

        Bus::assertNotDispatched(SendOutboundMessageJob::class);
    }
}
