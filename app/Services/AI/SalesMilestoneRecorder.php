<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\Chat;
use App\Models\SalesMilestone;
use App\Support\AiFeatureFlags;
use Illuminate\Support\Carbon;

final class SalesMilestoneRecorder
{
    /**
     * @param  array<string, mixed>|null  $payload
     */
    public function record(
        Chat $chat,
        string $milestone,
        string $source = SalesMilestone::SOURCE_SYSTEM,
        ?int $triggerMessageId = null,
        ?array $payload = null,
        ?Carbon $occurredAt = null,
    ): void {
        if (! AiFeatureFlags::enabled(AiFeatureFlags::SALES_STATE, (int) $chat->company_id)) {
            return;
        }

        $occurredAt ??= now();

        $exists = SalesMilestone::query()
            ->where('chat_id', $chat->id)
            ->where('milestone', $milestone)
            ->exists();

        if ($exists && ! in_array($milestone, [
            SalesMilestone::MILESTONE_DEFERRAL,
            SalesMilestone::MILESTONE_RE_ENGAGED,
            SalesMilestone::MILESTONE_PROPOSAL_SENT,
        ], true)) {
            return;
        }

        SalesMilestone::query()->create([
            'company_id' => (int) $chat->company_id,
            'chat_id' => (int) $chat->id,
            'contact_id' => $chat->contact_id !== null ? (int) $chat->contact_id : null,
            'milestone' => $milestone,
            'source' => $source,
            'trigger_message_id' => $triggerMessageId,
            'payload' => $payload,
            'occurred_at' => $occurredAt,
        ]);
    }

    /**
     * @param  array<string, mixed>  $previous
     * @param  array<string, mixed>  $next
     */
    public function recordStateTransitions(
        Chat $chat,
        array $previous,
        array $next,
        string $source = SalesMilestone::SOURCE_AI,
        ?int $triggerMessageId = null,
    ): void {
        $payload = [
            'score' => $next['score'] ?? null,
            'grade' => $next['grade'] ?? null,
            'funnel_stage_id' => $chat->funnel_stage_id,
        ];

        if (($previous['qualified'] ?? false) !== true && ($next['qualified'] ?? false) === true) {
            $this->record($chat, SalesMilestone::MILESTONE_QUALIFIED, $source, $triggerMessageId, $payload);
        }

        if (($previous['budget_known'] ?? false) !== true && ($next['budget_known'] ?? false) === true) {
            $this->record($chat, SalesMilestone::MILESTONE_BUDGET_CAPTURED, $source, $triggerMessageId, $payload);
        }

        if (($previous['decision_maker_known'] ?? false) !== true && ($next['decision_maker_known'] ?? false) === true) {
            $this->record($chat, SalesMilestone::MILESTONE_DM_CAPTURED, $source, $triggerMessageId, $payload);
        }

        if (($previous['timeline_known'] ?? false) !== true && ($next['timeline_known'] ?? false) === true) {
            $this->record($chat, SalesMilestone::MILESTONE_TIMELINE_CAPTURED, $source, $triggerMessageId, $payload);
        }

        if (($previous['requirements_known'] ?? false) !== true && ($next['requirements_known'] ?? false) === true) {
            $this->record($chat, SalesMilestone::MILESTONE_REQUIREMENTS_CAPTURED, $source, $triggerMessageId, $payload);
        }

        if (($previous['next_action'] ?? '') !== ChatSalesStateService::NA_PRESENT_OFFER
            && ($next['next_action'] ?? '') === ChatSalesStateService::NA_PRESENT_OFFER
        ) {
            $this->record($chat, SalesMilestone::MILESTONE_PROPOSAL_SENT, $source, $triggerMessageId, $payload);
        }
    }
}
