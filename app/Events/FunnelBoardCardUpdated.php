<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Funnel;
use App\Tenancy\TenantChannels;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class FunnelBoardCardUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  array<string, mixed>|null  $card
     */
    public function __construct(
        public readonly int $funnelId,
        public readonly int $chatId,
        public readonly ?int $stageId,
        public readonly ?int $actorUserId,
        public readonly string $source,
        public readonly ?array $card,
    ) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        $companyId = (int) Funnel::withoutGlobalScope('tenant')->whereKey($this->funnelId)->value('company_id');

        return [new PrivateChannel(TenantChannels::funnelBoard($companyId, $this->funnelId))];
    }

    public function broadcastAs(): string
    {
        return 'card.updated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'funnel_id' => $this->funnelId,
            'chat_id' => $this->chatId,
            'stage_id' => $this->stageId,
            'actor_user_id' => $this->actorUserId,
            'source' => $this->source,
            'card' => $this->card,
        ];
    }
}
