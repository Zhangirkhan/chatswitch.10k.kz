<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

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

final class SendMessageApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['administrator', 'manager', 'employee'] as $roleName) {
            Role::findOrCreate($roleName);
        }
    }

    public function test_post_message_via_bearer_token_dispatches_job(): void
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

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk()->json('token');

        $response = $this->withToken($token)->postJson("/api/v1/chats/{$chat->id}/messages", [
            'message' => 'Привет из API',
        ]);

        $response->assertOk();
        $response->assertJsonPath('tone_profile_learning_scheduled', false);
        $response->assertJsonPath('message.sender_name', $user->name.' (Сотрудник)');
        $this->assertTrue(
            Message::query()
                ->where('chat_id', $chat->id)
                ->where('direction', 'outbound')
                ->where('body', 'like', '%Привет из API%')
                ->exists(),
        );

        Bus::assertDispatched(SendOutboundMessageJob::class);
    }
}
