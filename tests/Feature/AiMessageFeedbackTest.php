<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Chat;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class AiMessageFeedbackTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['administrator', 'manager', 'employee'] as $role) {
            Role::findOrCreate($role);
        }
    }

    public function test_administrator_can_submit_rating_for_ai_generated_message(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('administrator');

        $chat = Chat::factory()->create();
        $message = Message::query()->create([
            'chat_id' => $chat->id,
            'whatsapp_session_id' => $chat->whatsapp_session_id,
            'direction' => 'outbound',
            'type' => 'chat',
            'body' => 'Ответ от AI',
            'metadata' => ['ai' => ['generated' => true]],
            'message_timestamp' => now(),
        ]);

        $this->actingAs($admin)
            ->postJson(route('messages.ai-feedback', $message), ['rating' => 'good'])
            ->assertOk();

        $this->assertDatabaseHas('ai_message_ratings', [
            'message_id' => $message->id,
            'user_id' => $admin->id,
            'rating' => 'good',
        ]);
    }

    public function test_cannot_rate_non_ai_message(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('administrator');

        $chat = Chat::factory()->create();
        $message = Message::query()->create([
            'chat_id' => $chat->id,
            'whatsapp_session_id' => $chat->whatsapp_session_id,
            'direction' => 'outbound',
            'type' => 'chat',
            'body' => 'Обычное сообщение',
            'metadata' => null,
            'message_timestamp' => now(),
        ]);

        $this->actingAs($admin)
            ->postJson(route('messages.ai-feedback', $message), ['rating' => 'good'])
            ->assertStatus(422);
    }
}
