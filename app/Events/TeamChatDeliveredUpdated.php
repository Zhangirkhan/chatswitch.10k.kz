<?php

declare(strict_types=1);

namespace App\Events;

use App\Tenancy\TenantChannels;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class TeamChatDeliveredUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly int $conversationId,
        public readonly int $recipientUserId,
        public readonly int $lastDeliveredMessageId,
    ) {}

    /** @return array<int, PrivateChannel> */
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
        return 'team.delivered';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'conversation_id' => $this->conversationId,
            'recipient_user_id' => $this->recipientUserId,
            'last_delivered_message_id' => $this->lastDeliveredMessageId,
        ];
    }
}
