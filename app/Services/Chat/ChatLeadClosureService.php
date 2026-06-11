<?php

declare(strict_types=1);

namespace App\Services\Chat;

use App\Events\ChatsListNotify;
use App\Models\Chat;
use App\Services\AI\ChatNurtureSequenceService;
use App\Services\AI\DealOutcomeRecorder;
use App\Support\AiFeatureFlags;
use App\Support\ChatBroadcastAudience;
use App\Services\Push\MobilePushService;
use App\Support\SafeBroadcast;
use Illuminate\Support\Carbon;

final class ChatLeadClosureService
{
    public function __construct(
        private readonly ChatNurtureSequenceService $nurtureSequence,
    ) {}

    public function close(Chat $chat, ?bool $won = null, ?string $reason = null): Chat
    {
        if ($chat->is_lead_closed) {
            return $chat;
        }

        $chat->forceFill([
            'is_lead_closed' => true,
            'lead_closed_at' => now(),
        ])->save();

        $this->nurtureSequence->cancelForChat($chat, 'lead_closed');

        if ($won !== null && AiFeatureFlags::enabled(AiFeatureFlags::WIN_LOSS_LEARNING, $chat->company_id)) {
            app(DealOutcomeRecorder::class)->recordManualClose($chat, $won, $reason);
        }

        $this->broadcastListUpdate($chat, 'lead_closed');

        return $chat->fresh() ?? $chat;
    }

    public function reopen(Chat $chat): Chat
    {
        if (! $chat->is_lead_closed) {
            return $chat;
        }

        $chat->forceFill([
            'is_lead_closed' => false,
            'lead_closed_at' => null,
        ])->save();

        $this->broadcastListUpdate($chat, 'lead_reopened');

        return $chat->fresh() ?? $chat;
    }

    public function reopenAfterInboundIfNeeded(Chat $chat): bool
    {
        if (! $chat->is_lead_closed) {
            return false;
        }

        $chat->forceFill([
            'is_lead_closed' => false,
            'lead_closed_at' => null,
        ])->save();

        $this->broadcastListUpdate($chat, 'lead_reopened');

        return true;
    }

    private function broadcastListUpdate(Chat $chat, string $kind): void
    {
        $recipientUserIds = ChatBroadcastAudience::userIdsWithAccessToChat($chat);
        if ($recipientUserIds === []) {
            return;
        }

        $chat->loadMissing(['contact', 'whatsappSession']);

        SafeBroadcast::dispatch(new ChatsListNotify(
            chatId: $chat->id,
            kind: $kind,
            title: $kind === 'lead_closed' ? 'Лид закрыт' : 'Лид снова открыт',
            body: ChatBroadcastAudience::chatDisplayName($chat),
            iconUrl: ChatBroadcastAudience::absoluteIconUrl($chat->contact?->profile_picture_url),
            isMuted: (bool) $chat->is_muted,
            recipientUserIds: $recipientUserIds,
            extra: [
                'is_lead_closed' => (bool) $chat->is_lead_closed,
                'lead_closed_at' => $chat->lead_closed_at instanceof Carbon
                    ? $chat->lead_closed_at->toIso8601String()
                    : null,
            ],
        ), 'chat-lead-closure');

        app(MobilePushService::class)->notifyLeadUpdate($chat, $kind, $recipientUserIds);
    }
}
