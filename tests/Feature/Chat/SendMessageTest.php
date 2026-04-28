<?php

declare(strict_types=1);

namespace Tests\Feature\Chat;

use App\Jobs\SendOutboundMessageJob;
use App\Models\Chat;
use App\Models\Message;
use App\Models\ChatAssignment;
use App\Models\User;
use App\Models\WhatsappSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class SendMessageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['administrator', 'manager', 'employee'] as $r) {
            Role::findOrCreate($r);
        }
    }

    public function test_assigned_employee_can_send_message(): void
    {
        Bus::fake([SendOutboundMessageJob::class]);

        $user = User::factory()->create();
        $user->assignRole('employee');

        $session = WhatsappSession::factory()->create();
        $chat = Chat::factory()->create(['whatsapp_session_id' => $session->id]);

        ChatAssignment::create([
            'chat_id' => $chat->id,
            'user_id' => $user->id,
            'assigned_by' => $user->id,
        ]);

        $response = $this->actingAs($user)
            ->postJson("/chats/{$chat->id}/send-message", ['message' => 'Hello']);

        $response->assertOk();
        $this->assertTrue(
            Message::query()
                ->where('chat_id', $chat->id)
                ->where('direction', 'outbound')
                ->where('body', 'like', '%Hello%')
                ->exists(),
        );

        Bus::assertDispatched(SendOutboundMessageJob::class, function ($job) {
            $body = (string) ($job->payload['body'] ?? '');

            return $job->payloadType === 'text' && str_contains($body, 'Hello');
        });
    }

    public function test_unassigned_employee_cannot_send_message(): void
    {
        $user = User::factory()->create();
        $user->assignRole('employee');

        $session = WhatsappSession::factory()->create();
        $chat = Chat::factory()->create(['whatsapp_session_id' => $session->id]);

        $this->actingAs($user)
            ->postJson("/chats/{$chat->id}/send-message", ['message' => 'Hello'])
            ->assertForbidden();
    }
}
