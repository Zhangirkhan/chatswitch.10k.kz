<?php

declare(strict_types=1);

namespace App\Events;

use App\Tenancy\TenantChannels;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class UserTyping implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly int $chatId,
        public readonly int $userId,
        public readonly string $userName,
    ) {}

    /** @return array<Channel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel(TenantChannels::chat(TenantChannels::companyIdForChat($this->chatId), $this->chatId))];
    }

    public function broadcastAs(): string
    {
        return 'user.typing';
    }
}
