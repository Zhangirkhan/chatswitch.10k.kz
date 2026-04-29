<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Chat */
final class ChatResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'whatsapp_chat_id' => $this->whatsapp_chat_id,
            'whatsapp_session_id' => $this->whatsapp_session_id,
            'contact_id' => $this->contact_id,
            'chat_name' => $this->chat_name,
            'is_group' => (bool) $this->is_group,
            'last_message_text' => $this->last_message_text,
            'last_message_at' => $this->last_message_at?->toIso8601String(),
            'last_message_direction' => $this->last_message_direction,
            'unread_count' => (int) $this->unread_count,
            'is_archived' => (bool) $this->is_archived,
            'is_pinned' => (bool) $this->is_pinned,
            'is_muted' => (bool) $this->is_muted,
            'muted_until' => $this->muted_until?->toIso8601String(),
            'is_favorite' => (bool) $this->is_favorite,
            'pinned_message_id' => $this->pinned_message_id,
            'contact' => $this->whenLoaded('contact', fn () => [
                'id' => $this->contact?->id,
                'name' => $this->contact?->name,
                'phone_number' => $this->contact?->phone_number,
            ]),
            'whatsapp_session' => $this->whenLoaded('whatsappSession', fn () => [
                'id' => $this->whatsappSession?->id,
                'session_name' => $this->whatsappSession?->session_name,
                'display_name' => $this->whatsappSession?->display_name,
                'display_color' => $this->whatsappSession?->display_color,
                'phone_number' => $this->whatsappSession?->phone_number,
                'status' => $this->whatsappSession?->status,
            ]),
            'assignments' => $this->whenLoaded('assignments', fn () => $this->assignments->map(fn ($a) => [
                'user_id' => $a->user_id,
                'user' => $a->relationLoaded('user') && $a->user ? [
                    'id' => $a->user->id,
                    'name' => $a->user->name,
                ] : null,
            ])->values()->all()),
            'departments' => $this->whenLoaded('departments', fn () => $this->departments->map(fn ($d) => [
                'id' => $d->id,
                'name' => $d->name,
            ])->values()->all()),
            'latest_message' => MessageResource::make($this->whenLoaded('latestMessage')),
            'pinned_message' => $this->whenLoaded('pinnedMessage', function () {
                $p = $this->pinnedMessage;

                return $p ? [
                    'id' => $p->id,
                    'direction' => $p->direction,
                    'type' => $p->type,
                    'body' => $p->body,
                    'sender_name' => $p->sender_name,
                ] : null;
            }),
        ];
    }
}
