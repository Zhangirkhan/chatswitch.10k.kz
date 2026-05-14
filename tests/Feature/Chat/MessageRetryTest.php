<?php

declare(strict_types=1);

namespace Tests\Feature\Chat;

use App\Jobs\SendOutboundMessageJob;
use App\Models\Chat;
use App\Models\ChatAssignment;
use App\Models\Message;
use App\Models\User;
use App\Models\WhatsappSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class MessageRetryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['administrator', 'manager', 'employee'] as $r) {
            Role::findOrCreate($r);
        }
    }

    public function test_sender_can_retry_failed_outbound_and_job_is_dispatched(): void
    {
        Bus::fake([SendOutboundMessageJob::class]);

        $user = User::factory()->create();
        $user->assignRole('employee');

        $session = WhatsappSession::factory()->create();
        $user->whatsappSessions()->attach($session->id);

        $chat = Chat::factory()->create(['whatsapp_session_id' => $session->id]);
        ChatAssignment::create([
            'chat_id' => $chat->id,
            'user_id' => $user->id,
            'assigned_by' => $user->id,
        ]);

        $message = Message::create([
            'chat_id' => $chat->id,
            'whatsapp_session_id' => $session->id,
            'whatsapp_message_id' => null,
            'direction' => 'outbound',
            'type' => 'chat',
            'body' => '[Test] Hello retry',
            'sent_by_user_id' => $user->id,
            'sender_name' => $user->name,
            'ack' => 'failed',
            'message_timestamp' => now(),
        ]);

        $this->actingAs($user)
            ->postJson(route('messages.retry', $message))
            ->assertOk()
            ->assertJson(['success' => true]);

        $message->refresh();
        $this->assertSame('pending', $message->ack);
        $this->assertNull($message->whatsapp_message_id);

        Bus::assertDispatched(SendOutboundMessageJob::class, function (SendOutboundMessageJob $job) use ($message): bool {
            return $job->messageId === $message->id
                && $job->payloadType === 'text'
                && str_contains((string) ($job->payload['body'] ?? ''), 'Hello retry');
        });
    }

    public function test_other_employee_cannot_retry_message(): void
    {
        Bus::fake([SendOutboundMessageJob::class]);

        $owner = User::factory()->create();
        $owner->assignRole('employee');
        $other = User::factory()->create();
        $other->assignRole('employee');

        $session = WhatsappSession::factory()->create();
        $owner->whatsappSessions()->attach($session->id);
        $other->whatsappSessions()->attach($session->id);

        $chat = Chat::factory()->create(['whatsapp_session_id' => $session->id]);
        ChatAssignment::create([
            'chat_id' => $chat->id,
            'user_id' => $owner->id,
            'assigned_by' => $owner->id,
        ]);
        ChatAssignment::create([
            'chat_id' => $chat->id,
            'user_id' => $other->id,
            'assigned_by' => $other->id,
        ]);

        $message = Message::create([
            'chat_id' => $chat->id,
            'whatsapp_session_id' => $session->id,
            'whatsapp_message_id' => null,
            'direction' => 'outbound',
            'type' => 'chat',
            'body' => 'Private',
            'sent_by_user_id' => $owner->id,
            'sender_name' => $owner->name,
            'ack' => 'failed',
            'message_timestamp' => now(),
        ]);

        $this->actingAs($other)
            ->postJson(route('messages.retry', $message))
            ->assertForbidden();

        Bus::assertNothingDispatched();
    }

    public function test_cannot_retry_delivered_message(): void
    {
        Bus::fake([SendOutboundMessageJob::class]);

        $user = User::factory()->create();
        $user->assignRole('employee');

        $session = WhatsappSession::factory()->create();
        $user->whatsappSessions()->attach($session->id);

        $chat = Chat::factory()->create(['whatsapp_session_id' => $session->id]);
        ChatAssignment::create([
            'chat_id' => $chat->id,
            'user_id' => $user->id,
            'assigned_by' => $user->id,
        ]);

        $message = Message::create([
            'chat_id' => $chat->id,
            'whatsapp_session_id' => $session->id,
            'whatsapp_message_id' => 'true_123@c.us_3EB0',
            'direction' => 'outbound',
            'type' => 'chat',
            'body' => 'Sent',
            'sent_by_user_id' => $user->id,
            'sender_name' => $user->name,
            'ack' => 'delivered',
            'message_timestamp' => now(),
        ]);

        $this->actingAs($user)
            ->postJson(route('messages.retry', $message))
            ->assertStatus(422);

        Bus::assertNothingDispatched();
    }

    public function test_retry_forward_uses_stored_source_id(): void
    {
        Bus::fake([SendOutboundMessageJob::class]);

        $user = User::factory()->create();
        $user->assignRole('employee');

        $session = WhatsappSession::factory()->create();
        $user->whatsappSessions()->attach($session->id);

        $chat = Chat::factory()->create(['whatsapp_session_id' => $session->id]);
        ChatAssignment::create([
            'chat_id' => $chat->id,
            'user_id' => $user->id,
            'assigned_by' => $user->id,
        ]);

        $sourceWa = 'true_999@c.us_ABC';
        $message = Message::create([
            'chat_id' => $chat->id,
            'whatsapp_session_id' => $session->id,
            'whatsapp_message_id' => null,
            'direction' => 'outbound',
            'type' => 'image',
            'body' => '[x] 📷 Фото',
            'metadata' => ['forward_source_whatsapp_message_id' => $sourceWa],
            'sent_by_user_id' => $user->id,
            'sender_name' => $user->name,
            'is_forwarded' => true,
            'ack' => 'failed',
            'message_timestamp' => now(),
        ]);

        $this->actingAs($user)
            ->postJson(route('messages.retry', $message))
            ->assertOk();

        Bus::assertDispatched(SendOutboundMessageJob::class, function (SendOutboundMessageJob $job) use ($message, $sourceWa): bool {
            return $job->messageId === $message->id
                && $job->payloadType === 'forward'
                && ($job->payload['source_whatsapp_message_id'] ?? null) === $sourceWa;
        });
    }
}
