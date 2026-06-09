<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Services\Funnel\ChatFunnelStateService;
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
        $funnel = $this->resolveFunnelPayload();
        $funnelStage = $this->resolveFunnelStagePayload();

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
            'is_lead_closed' => (bool) $this->is_lead_closed,
            'lead_closed_at' => $this->lead_closed_at?->toIso8601String(),
            'pinned_message_id' => $this->pinned_message_id,
            'ai_enabled' => (bool) $this->ai_enabled,
            'ai_mode' => (string) ($this->ai_mode ?? 'auto'),
            'funnel_id' => $this->funnel_id,
            'funnel_stage_id' => $this->funnel_stage_id,
            'funnel_tracking_enabled' => (bool) $this->funnel_tracking_enabled,
            'funnel_stage_locked' => (bool) $this->funnel_stage_locked,
            'funnel' => $funnel,
            'funnel_stage' => $funnelStage,
            'funnel_progress_percent' => $this->when(
                $this->getAttribute('funnel_progress_percent') !== null,
                fn () => $this->getAttribute('funnel_progress_percent'),
            ),
            'funnel_progress' => $this->when(
                $this->getAttribute('funnel_progress') !== null,
                fn () => $this->getAttribute('funnel_progress'),
            ),
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

    /**
     * @return array<string, mixed>|null
     */
    private function resolveFunnelPayload(): ?array
    {
        $preset = $this->getAttribute('funnel');
        if (is_array($preset)) {
            return $preset;
        }

        if ($this->relationLoaded('funnel') && $this->funnel !== null) {
            return $this->funnel->only(['id', 'name', 'color']);
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveFunnelStagePayload(): ?array
    {
        $preset = $this->getAttribute('funnel_stage');
        if (is_array($preset)) {
            return $preset;
        }

        if ($this->relationLoaded('funnelStage') && $this->funnelStage !== null) {
            return $this->funnelStage->only(['id', 'name', 'color', 'stage_type', 'position']);
        }

        return null;
    }

    public static function withFunnelDetails(\App\Models\Chat $chat): self
    {
        $state = app(ChatFunnelStateService::class);
        foreach ($state->inertiaExtras($chat) as $key => $value) {
            $chat->setAttribute($key, $value);
        }

        return new self($chat);
    }
}
