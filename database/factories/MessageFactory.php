<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Chat;
use App\Models\Message;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Message>
 */
final class MessageFactory extends Factory
{
    protected $model = Message::class;

    public function definition(): array
    {
        return [
            'chat_id' => Chat::factory(),
            'whatsapp_session_id' => null,
            'whatsapp_message_id' => fake()->unique()->uuid(),
            'direction' => 'inbound',
            'type' => 'chat',
            'body' => fake()->sentence(),
            'sender_phone' => null,
            'sender_name' => fake()->name(),
            'sent_by_user_id' => null,
            'is_forwarded' => false,
            'quoted_message_id' => null,
            'ack' => 'delivered',
            'message_timestamp' => now(),
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (Message $message): void {
            if ($message->chat_id === null) {
                return;
            }

            $chat = Chat::query()->find($message->chat_id);
            if ($chat === null) {
                return;
            }

            if ($message->whatsapp_session_id === null) {
                $message->whatsapp_session_id = $chat->whatsapp_session_id;
            }
        });
    }

    public function inbound(): static
    {
        return $this->state(fn (): array => [
            'direction' => 'inbound',
            'sent_by_user_id' => null,
        ]);
    }

    public function outbound(): static
    {
        return $this->state(fn (): array => [
            'direction' => 'outbound',
        ]);
    }
}
