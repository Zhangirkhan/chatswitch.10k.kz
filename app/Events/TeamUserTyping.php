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

final class TeamUserTyping implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly int $conversationId,
        public readonly int $userId,
        public readonly string $userName,
    ) {}

    /** @return array<int, Channel> */
    public function broadcastOn(): array
    {
        $companyId = TenantChannels::companyIdForConversation($this->conversationId);

        return [new PrivateChannel(TenantChannels::teamConversation($companyId, $this->conversationId))];
    }

    public function broadcastAs(): string
    {
        return 'team.typing';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'conversation_id' => $this->conversationId,
            'user_id' => $this->userId,
            'user_name' => $this->userName,
        ];
    }
}
