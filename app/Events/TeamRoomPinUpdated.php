<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class TeamRoomPinUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  array{id: int, sender_name: string, body_preview: string}|null  $roomPinnedMessage
     */
    public function __construct(
        public readonly int $conversationId,
        public readonly ?array $roomPinnedMessage,
    ) {}

    /** @return array<int, Channel> */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('team-conversation.'.$this->conversationId),
        ];
    }

    public function broadcastAs(): string
    {
        return 'team.room-pin';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'conversation_id' => $this->conversationId,
            'room_pinned_message' => $this->roomPinnedMessage,
        ];
    }
}
