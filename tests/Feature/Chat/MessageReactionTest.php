<?php

declare(strict_types=1);

namespace Tests\Feature\Chat;

use App\Models\Chat;
use App\Models\ChatAssignment;
use App\Models\Message;
use App\Models\MessageReaction;
use App\Models\User;
use App\Models\WhatsappSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class MessageReactionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['administrator', 'manager', 'employee'] as $r) {
            Role::findOrCreate($r);
        }
    }

    public function test_reaction_is_synced_to_whatsapp_before_it_is_saved(): void
    {
        Http::fake([
            '127.0.0.1:3050/api/react-message' => Http::response(['success' => true], 200),
        ]);

        /** @var User $user */
        $user = User::factory()->create();
        $user->assignRole('employee');

        $session = WhatsappSession::factory()->create(['session_name' => 'wa-test']);
        $chat = Chat::factory()->create(['whatsapp_session_id' => $session->id]);
        ChatAssignment::create([
            'chat_id' => $chat->id,
            'user_id' => $user->id,
            'assigned_by' => $user->id,
        ]);
        $message = Message::query()->create([
            'chat_id' => $chat->id,
            'whatsapp_session_id' => $session->id,
            'whatsapp_message_id' => 'wamid.test-1',
            'direction' => 'inbound',
            'type' => 'chat',
            'body' => 'hello',
            'sender_phone' => '77011234567',
            'sender_name' => 'Client',
        ]);

        $response = $this->actingAs($user)
            ->postJson(route('messages.react', $message), ['emoji' => '👍']);

        $response->assertOk()
            ->assertJsonPath('success', true);

        Http::assertSent(function ($request): bool {
            return $request->url() === 'http://127.0.0.1:3050/api/react-message'
                && $request->hasHeader('X-WhatsApp-Session', 'wa-test')
                && $request['messageId'] === 'wamid.test-1'
                && $request['reaction'] === '👍';
        });

        $this->assertDatabaseHas('message_reactions', [
            'message_id' => $message->id,
            'user_id' => $user->id,
            'emoji' => '👍',
        ]);
    }

    public function test_reaction_is_not_saved_locally_when_whatsapp_sync_fails(): void
    {
        Http::fake([
            '127.0.0.1:3050/api/react-message' => Http::response(['success' => false, 'error' => 'Client not ready'], 500),
        ]);

        /** @var User $user */
        $user = User::factory()->create();
        $user->assignRole('employee');

        $session = WhatsappSession::factory()->create(['session_name' => 'wa-test']);
        $chat = Chat::factory()->create(['whatsapp_session_id' => $session->id]);
        ChatAssignment::create([
            'chat_id' => $chat->id,
            'user_id' => $user->id,
            'assigned_by' => $user->id,
        ]);
        $message = Message::query()->create([
            'chat_id' => $chat->id,
            'whatsapp_session_id' => $session->id,
            'whatsapp_message_id' => 'wamid.test-2',
            'direction' => 'inbound',
            'type' => 'chat',
            'body' => 'hello',
            'sender_phone' => '77011234567',
            'sender_name' => 'Client',
        ]);

        $this->actingAs($user)
            ->postJson(route('messages.react', $message), ['emoji' => '🔥'])
            ->assertStatus(502)
            ->assertJsonPath('success', false);

        $this->assertDatabaseCount('message_reactions', 0);
    }

    public function test_removing_reaction_sends_empty_reaction_to_whatsapp(): void
    {
        Http::fake([
            '127.0.0.1:3050/api/react-message' => Http::response(['success' => true], 200),
        ]);

        /** @var User $user */
        $user = User::factory()->create();
        $user->assignRole('employee');

        $session = WhatsappSession::factory()->create(['session_name' => 'wa-test']);
        $chat = Chat::factory()->create(['whatsapp_session_id' => $session->id]);
        ChatAssignment::create([
            'chat_id' => $chat->id,
            'user_id' => $user->id,
            'assigned_by' => $user->id,
        ]);
        $message = Message::query()->create([
            'chat_id' => $chat->id,
            'whatsapp_session_id' => $session->id,
            'whatsapp_message_id' => 'wamid.test-3',
            'direction' => 'inbound',
            'type' => 'chat',
            'body' => 'hello',
            'sender_phone' => '77011234567',
            'sender_name' => 'Client',
        ]);

        MessageReaction::query()->create([
            'message_id' => $message->id,
            'user_id' => $user->id,
            'emoji' => '🔥',
        ]);

        $response = $this->actingAs($user)
            ->postJson(route('messages.react', $message), ['emoji' => '🔥']);

        $response->assertOk()
            ->assertJsonPath('success', true);

        Http::assertSent(function ($request): bool {
            return $request->url() === 'http://127.0.0.1:3050/api/react-message'
                && $request['messageId'] === 'wamid.test-3'
                && $request['reaction'] === '';
        });

        $this->assertDatabaseCount('message_reactions', 0);
    }

    public function test_employee_cannot_react_on_message_in_unassigned_chat(): void
    {
        Http::fake();

        /** @var User $user */
        $user = User::factory()->create();
        $user->assignRole('employee');

        $session = WhatsappSession::factory()->create(['session_name' => 'wa-test']);
        $chat = Chat::factory()->create(['whatsapp_session_id' => $session->id]);
        $message = Message::query()->create([
            'chat_id' => $chat->id,
            'whatsapp_session_id' => $session->id,
            'whatsapp_message_id' => 'wamid.test-4',
            'direction' => 'inbound',
            'type' => 'chat',
            'body' => 'hello',
            'sender_phone' => '77011234567',
            'sender_name' => 'Client',
        ]);

        $this->actingAs($user)
            ->postJson(route('messages.react', $message), ['emoji' => '👍'])
            ->assertForbidden();

        Http::assertNothingSent();
        $this->assertDatabaseCount('message_reactions', 0);
    }
}
