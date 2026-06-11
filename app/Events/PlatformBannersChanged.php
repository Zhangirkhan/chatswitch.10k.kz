<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class PlatformBannersChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly ?int $companyId,
    ) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        if ($this->companyId === null) {
            return [new PrivateChannel('platform.admin-banners')];
        }

        return [new PrivateChannel("t.{$this->companyId}.platform-banners")];
    }

    public function broadcastAs(): string
    {
        return 'platform-banners.changed';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return ['company_id' => $this->companyId];
    }
}
