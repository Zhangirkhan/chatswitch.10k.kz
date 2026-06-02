<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Message */
final class MessageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'chat_id' => $this->chat_id,
            'direction' => $this->direction,
            'type' => $this->type,
            'body' => $this->body,
            'metadata' => $this->metadata,
            'sender_phone' => $this->sender_phone,
            'sender_name' => $this->sender_name,
            'sent_by_user_id' => $this->sent_by_user_id,
            'is_forwarded' => (bool) $this->is_forwarded,
            'quoted_message_id' => $this->quoted_message_id,
            'ack' => $this->ack,
            'message_timestamp' => $this->message_timestamp?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'sent_by_user' => $this->whenLoaded('sentByUser', fn () => [
                'id' => $this->sentByUser?->id,
                'name' => $this->sentByUser?->name,
            ]),
            'media' => $this->whenLoaded('media', fn () => $this->media->map(fn ($m) => [
                'id' => $m->id,
                'mime_type' => $m->mime_type,
                'filename' => $m->filename,
                'url' => url('/api/v1/media/'.$m->id),
            ])->values()->all()),
            'reactions' => $this->whenLoaded('reactions', fn () => $this->reactions->map(fn ($r) => [
                'emoji' => $r->emoji,
                'user' => $r->relationLoaded('user') && $r->user ? [
                    'id' => $r->user->id,
                    'name' => $r->user->name,
                ] : null,
            ])->values()->all()),
            'quoted_message' => $this->whenLoaded('quotedMessage', function () {
                $q = $this->quotedMessage;

                return $q ? [
                    'id' => $q->id,
                    'whatsapp_message_id' => $q->whatsapp_message_id,
                    'direction' => $q->direction,
                    'type' => $q->type,
                    'body' => $q->body,
                    'sender_name' => $q->sender_name,
                    'sender_phone' => $q->sender_phone,
                    'sent_by_user_id' => $q->sent_by_user_id,
                ] : null;
            }),
        ];
    }
}
