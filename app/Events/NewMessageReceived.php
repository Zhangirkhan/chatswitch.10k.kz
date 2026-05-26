<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Chat;
use App\Models\Message;
use App\Support\ChatBroadcastAudience;
use App\Tenancy\TenantChannels;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class NewMessageReceived implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly Message $message,
        public readonly int $chatId,
    ) {}

    /** @return array<Channel> */
    public function broadcastOn(): array
    {
        $chat = Chat::withoutGlobalScope('tenant')->find($this->chatId);
        $companyId = (int) ($chat?->company_id ?? 0);
        $channels = [new PrivateChannel(TenantChannels::chat($companyId, $this->chatId))];

        if ($chat) {
            foreach (ChatBroadcastAudience::userIdsWithAccessToChat($chat) as $userId) {
                $channels[] = new PrivateChannel(TenantChannels::chatsList($companyId, $userId));
            }
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'message.received';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        $chat = Chat::query()->with(['contact', 'whatsappSession'])->find($this->chatId);
        $desktop = null;
        if ($chat !== null) {
            $desktop = ChatBroadcastAudience::payloadForNewMessage($chat, $this->message);
        }

        return [
            'desktop' => $desktop,
            'message' => [
                'id' => $this->message->id,
                'chat_id' => $this->message->chat_id,
                'whatsapp_session_id' => $this->message->whatsapp_session_id,
                'direction' => $this->message->direction,
                'type' => $this->message->type,
                'body' => $this->message->body,
                'metadata' => $this->message->metadata,
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
                'whatsapp_session' => $this->message->whatsappSession ? [
                    'id' => $this->message->whatsappSession->id,
                    'phone_number' => $this->message->whatsappSession->phone_number,
                    'display_name' => $this->message->whatsappSession->display_name,
                ] : null,
                'quoted_message' => $this->message->quotedMessage ? [
                    'id' => $this->message->quotedMessage->id,
                    'direction' => $this->message->quotedMessage->direction,
                    'type' => $this->message->quotedMessage->type,
                    'body' => $this->message->quotedMessage->body,
                    'sender_name' => $this->message->quotedMessage->sender_name,
                    'sender_phone' => $this->message->quotedMessage->sender_phone,
                    'sent_by_user' => $this->message->quotedMessage->sentByUser ? [
                        'id' => $this->message->quotedMessage->sentByUser->id,
                        'name' => $this->message->quotedMessage->sentByUser->name,
                    ] : null,
                    'media' => $this->message->quotedMessage->media->map(fn ($m) => [
                        'id' => $m->id,
                        'mime_type' => $m->mime_type,
                        'filename' => $m->filename,
                    ])->toArray(),
                ] : null,
            ],
        ];
    }
}
