<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Лёгкое уведомление на канал chats.list.{userId} (звонки, назначения и т.д.).
 */
final class ChatsListNotify implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  list<int>  $recipientUserIds
     * @param  array<string, mixed>  $extra
     */
    public function __construct(
        public readonly int $chatId,
        public readonly string $kind,
        public readonly string $title,
        public readonly string $body,
        public readonly ?string $iconUrl,
        public readonly bool $isMuted,
        public readonly array $recipientUserIds,
        public readonly array $extra = [],
    ) {}

    /** @return array<Channel> */
    public function broadcastOn(): array
    {
        $channels = [];
        foreach ($this->recipientUserIds as $userId) {
            $channels[] = new PrivateChannel('chats.list.'.$userId);
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'chats.notify';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return array_merge([
            'kind' => $this->kind,
            'chat_id' => $this->chatId,
            'title' => $this->title,
            'body' => $this->body,
            'icon' => $this->iconUrl,
            'is_muted' => $this->isMuted,
        ], $this->extra);
    }
}
