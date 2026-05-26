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

/**
 * Сообщаем клиенту, что у исходящего сообщения поменялся статус доставки
 * (sent → delivered → read). Прилетает сразу, как WhatsApp пришлёт webhook,
 * чтобы галочки в UI обновлялись в реальном времени.
 *
 * ShouldBroadcastNow — без постановки в очередь broadcasts: иначе событие
 * обрабатывается отдельным воркером и статус в чате отстаёт до F5.
 */
final class MessageAckUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly int $chatId,
        public readonly int $messageId,
        public readonly string $ack,
    ) {}

    /** @return array<Channel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel(TenantChannels::chat(TenantChannels::companyIdForChat($this->chatId), $this->chatId))];
    }

    public function broadcastAs(): string
    {
        return 'message.ack';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->messageId,
            'ack' => $this->ack,
        ];
    }
}
