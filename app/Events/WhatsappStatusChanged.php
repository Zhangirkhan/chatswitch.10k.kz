<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class WhatsappStatusChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly string $sessionName,
        public readonly string $status,
        public readonly ?string $phoneNumber = null,
    ) {}

    /** @return array<Channel> */
    public function broadcastOn(): array
    {
        return [new Channel('whatsapp-status')];
    }

    public function broadcastAs(): string
    {
        return 'status.changed';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'session' => $this->sessionName,
            'status' => $this->status,
            'phone_number' => $this->phoneNumber,
        ];
    }
}
