<?php

declare(strict_types=1);

namespace App\Events;

use App\Tenancy\TenantChannels;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class TeamChatReadUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly int $conversationId,
        public readonly int $readerUserId,
        public readonly int $lastReadMessageId,
    ) {}

    /** @return array<int, Channel> */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel(TenantChannels::teamConversation(
                TenantChannels::companyIdForConversation($this->conversationId),
                $this->conversationId,
            )),
        ];
    }

    public function broadcastAs(): string
    {
        return 'team.read';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'conversation_id' => $this->conversationId,
            'reader_user_id' => $this->readerUserId,
            'last_read_message_id' => $this->lastReadMessageId,
        ];
    }
}
