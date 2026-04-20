<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Message;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class NewMessageReceived implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly Message $message,
        public readonly int $chatId,
    ) {}

    /** @return array<Channel> */
    public function broadcastOn(): array
    {
        return [
            new Channel("chat.{$this->chatId}"),
            new Channel('chats.list'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'message.received';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'message' => [
                'id' => $this->message->id,
                'chat_id' => $this->message->chat_id,
                'whatsapp_session_id' => $this->message->whatsapp_session_id,
                'direction' => $this->message->direction,
                'type' => $this->message->type,
                'body' => $this->message->body,
                'sender_phone' => $this->message->sender_phone,
                'sender_name' => $this->message->sender_name,
                'sent_by_user_id' => $this->message->sent_by_user_id,
                'is_forwarded' => $this->message->is_forwarded,
                'ack' => $this->message->ack,
                'message_timestamp' => $this->message->message_timestamp?->toISOString(),
                'created_at' => $this->message->created_at?->toISOString(),
                'media' => $this->message->media->map(fn ($m) => [
                    'id' => $m->id,
                    'mime_type' => $m->mime_type,
                    'filename' => $m->filename,
                ])->toArray(),
                'sent_by_user' => $this->message->sentByUser ? [
                    'id' => $this->message->sentByUser->id,
                    'name' => $this->message->sentByUser->name,
                ] : null,
                'whatsapp_session' => [
                    'id' => $this->message->whatsappSession->id,
                    'phone_number' => $this->message->whatsappSession->phone_number,
                    'display_name' => $this->message->whatsappSession->display_name,
                ],
            ],
        ];
    }
}
