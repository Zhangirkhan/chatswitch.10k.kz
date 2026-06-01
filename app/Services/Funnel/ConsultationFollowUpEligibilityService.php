<?php

declare(strict_types=1);

namespace App\Services\Funnel;

use App\Models\AiFollowUpProposal;
use App\Models\Chat;
use App\Models\FunnelStageAiRule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

final class ConsultationFollowUpEligibilityService
{
    /**
     * @return Builder<Chat>
     */
    public function eligibleChatsQuery(FunnelStageAiRule $rule, Carbon $threshold): Builder
    {
        $silenceAfter = (string) ($rule->follow_up_silence_after ?? FunnelStageAiRule::FOLLOW_UP_SILENCE_OUTBOUND);
        $direction = $silenceAfter === FunnelStageAiRule::FOLLOW_UP_SILENCE_INBOUND ? 'inbound' : 'outbound';
        $oppositeDirection = $direction === 'inbound' ? 'outbound' : 'inbound';

        $cooldownHours = max(1, (int) $rule->follow_up_cooldown_hours);
        $cooldownSince = now()->subHours($cooldownHours);
        $stageId = (int) $rule->funnel_stage_id;

        return Chat::query()
            ->where('company_id', $rule->company_id)
            ->where('funnel_id', $rule->funnel_id)
            ->where('funnel_stage_id', $stageId)
            ->where('funnel_tracking_enabled', true)
            ->where('is_archived', false)
            ->where('is_group', false)
            ->where('last_message_direction', $direction)
            ->whereNotNull('last_message_at')
            ->where('last_message_at', '<=', $threshold)
            ->whereDoesntHave('messages', function (Builder $query) use ($oppositeDirection): void {
                $query
                    ->where('direction', $oppositeDirection)
                    ->whereColumn('message_timestamp', '>', 'chats.last_message_at');
            })
            ->whereDoesntHave('aiFollowUpProposals', function (Builder $query) use ($stageId, $cooldownSince): void {
                $query
                    ->where('funnel_stage_id', $stageId)
                    ->whereIn('status', [
                        AiFollowUpProposal::STATUS_PENDING,
                        AiFollowUpProposal::STATUS_NEEDS_MANAGER,
                    ])
                    ->where('created_at', '>=', $cooldownSince);
            })
            ->whereRaw(
                '(SELECT COUNT(*) FROM ai_follow_up_proposals p WHERE p.chat_id = chats.id AND p.funnel_stage_id = ? AND p.status IN (?, ?, ?) AND p.created_at >= ?) < ?',
                [
                    $stageId,
                    AiFollowUpProposal::STATUS_NEEDS_MANAGER,
                    AiFollowUpProposal::STATUS_SENT,
                    AiFollowUpProposal::STATUS_DISMISSED,
                    $cooldownSince,
                    max(1, (int) $rule->follow_up_max_count),
                ],
            );
    }
}
