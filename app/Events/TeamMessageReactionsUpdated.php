<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\TeamMessageReaction;
use App\Tenancy\TenantChannels;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class TeamMessageReactionsUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  iterable<TeamMessageReaction>  $reactions
     */
    public function __construct(
        public readonly int $conversationId,
        public readonly int $messageId,
        public readonly iterable $reactions,
    ) {}

    /** @return array<int, Channel> */
    public function broadcastOn(): array
    {
        $companyId = TenantChannels::companyIdForConversation($this->conversationId);

        return [new PrivateChannel(TenantChannels::teamConversation($companyId, $this->conversationId))];
    }

    public function broadcastAs(): string
    {
        return 'team.message.reactions';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'conversation_id' => $this->conversationId,
            'id' => $this->messageId,
            'reactions' => collect($this->reactions)
                ->map(fn (TeamMessageReaction $r): array => $r->toApiArray())
                ->values()
                ->all(),
        ];
    }
}
