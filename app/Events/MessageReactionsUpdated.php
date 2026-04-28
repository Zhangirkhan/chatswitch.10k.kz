<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\MessageReaction;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class MessageReactionsUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  iterable<MessageReaction>  $reactions
     */
    public function __construct(
        public readonly int $chatId,
        public readonly int $messageId,
        public readonly iterable $reactions,
    ) {}

    /** @return array<Channel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("chat.{$this->chatId}")];
    }

    public function broadcastAs(): string
    {
        return 'message.reactions';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->messageId,
            'reactions' => collect($this->reactions)->map(fn (MessageReaction $reaction): array => [
                'id' => $reaction->id,
                'message_id' => $reaction->message_id,
                'user_id' => $reaction->user_id,
                'external_id' => $reaction->external_id,
                'external_name' => $reaction->external_name,
                'emoji' => $reaction->emoji,
                'user' => $reaction->user ? [
                    'id' => $reaction->user->id,
                    'name' => $reaction->user->name,
                ] : null,
            ])->values()->all(),
        ];
    }
}
