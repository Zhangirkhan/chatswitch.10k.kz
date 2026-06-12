<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Enums\EntityMemorySubjectType;
use App\Models\Chat;
use App\Models\ContactTag;
use App\Models\DealOutcome;
use App\Models\FollowUpOutcome;
use App\Models\FunnelStage;
use App\Models\WinProbabilityScore;
use App\Services\Memory\EntityMemoryService;
use App\Models\SalesMilestone;
use App\Support\AiFeatureFlags;
use App\Support\FunnelStageType;
use Illuminate\Support\Facades\Log;

final class DealOutcomeRecorder
{
    public function __construct(
        private readonly EntityMemoryService $entityMemory,
        private readonly ChatSalesStateService $salesState,
        private readonly DealOutcomeStatsService $statsService,
        private readonly SalesMilestoneRecorder $milestoneRecorder,
    ) {}

    public function recordFromStageTransition(Chat $chat, FunnelStage $targetStage): void
    {
        if (! AiFeatureFlags::enabled(AiFeatureFlags::WIN_LOSS_LEARNING, $chat->company_id)) {
            return;
        }

        $won = $this->isWonStage($targetStage);
        $lost = $this->isLostStage($targetStage);

        if (! $won && ! $lost) {
            return;
        }

        $this->persist($chat, $won, DealOutcome::SOURCE_AUTO_STAGE, (int) $targetStage->id);
    }

    public function recordManualClose(Chat $chat, bool $won, ?string $reason = null): void
    {
        if (! AiFeatureFlags::enabled(AiFeatureFlags::WIN_LOSS_LEARNING, $chat->company_id)) {
            return;
        }

        $this->persist($chat, $won, DealOutcome::SOURCE_MANUAL_CLOSE, $chat->funnel_stage_id, $reason);
    }

    private function persist(
        Chat $chat,
        bool $won,
        string $source,
        ?int $funnelStageId,
        ?string $reasonOverride = null,
    ): void {
        if ($this->alreadyRecorded($chat)) {
            return;
        }

        $facts = $chat->contact_id !== null
            ? $this->entityMemory->readAiFacts(EntityMemorySubjectType::Contact, (int) $chat->contact_id)
            : [];

        $state = $this->salesState->freshState($chat);
        $reason = $reasonOverride ?? $this->inferReason($facts, $won, $chat);
        $industry = $this->inferIndustry($facts);

        $outcome = DealOutcome::query()->create([
            'company_id' => (int) $chat->company_id,
            'chat_id' => $chat->id,
            'contact_id' => $chat->contact_id,
            'won' => $won,
            'reason' => $reason,
            'industry' => $industry,
            'lead_score' => isset($state['score']) ? (int) $state['score'] : null,
            'lead_grade' => isset($state['grade']) ? (string) $state['grade'] : null,
            'sales_state_snapshot' => $state !== [] ? $state : null,
            'objections_at_close' => trim($facts['objections'] ?? '') ?: null,
            'funnel_stage_id' => $funnelStageId,
            'source' => $source,
            'closed_at' => now(),
        ]);

        WinProbabilityScore::query()
            ->where('chat_id', $chat->id)
            ->whereNull('deal_outcome_id')
            ->update(['deal_outcome_id' => $outcome->id]);

        FollowUpOutcome::query()
            ->where('chat_id', $chat->id)
            ->whereNull('deal_outcome_id')
            ->update(['deal_outcome_id' => $outcome->id]);

        $this->milestoneRecorder->record(
            $chat,
            $won ? SalesMilestone::MILESTONE_CLOSED_WON : SalesMilestone::MILESTONE_CLOSED_LOST,
            $source === DealOutcome::SOURCE_MANUAL_CLOSE
                ? SalesMilestone::SOURCE_MANAGER
                : SalesMilestone::SOURCE_SYSTEM,
            null,
            ['reason' => $reason, 'funnel_stage_id' => $funnelStageId],
        );

        Log::info('[deal-outcome] recorded', [
            'chat_id' => $chat->id,
            'won' => $won,
            'reason' => $reason,
        ]);

        $this->syncOutcomeTag($chat, $won);
        $this->statsService->forgetCache((int) $chat->company_id);
    }

    private function alreadyRecorded(Chat $chat): bool
    {
        return DealOutcome::query()
            ->where('chat_id', $chat->id)
            ->exists();
    }

    private function isWonStage(FunnelStage $stage): bool
    {
        return FunnelStageType::guessFromName($stage->name) === FunnelStageType::DONE;
    }

    private function isLostStage(FunnelStage $stage): bool
    {
        $name = mb_strtolower(trim($stage->name));

        foreach (['отказ', 'lost', 'reject', 'нецелев', 'спам', 'отмен'] as $needle) {
            if (str_contains($name, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, string>  $facts
     */
    private function inferReason(array $facts, bool $won, Chat $chat): string
    {
        if ($won) {
            return 'сделка закрыта';
        }

        $objections = mb_strtolower(trim($facts['objections'] ?? ''));
        if (str_contains($objections, 'дорог') || str_contains($objections, 'цен')) {
            return 'цена';
        }
        if (str_contains($objections, 'срок') || str_contains($objections, 'долго')) {
            return 'срок';
        }
        if (str_contains($objections, 'конкур') || str_contains($objections, 'друг')) {
            return 'конкурент';
        }

        $stageName = mb_strtolower((string) ($chat->funnelStage?->name ?? ''));
        if (str_contains($stageName, 'отказ') || str_contains($stageName, 'lost')) {
            return 'отказ';
        }

        return 'не указано';
    }

    /**
     * @param  array<string, string>  $facts
     */
    private function inferIndustry(array $facts): ?string
    {
        $haystack = mb_strtolower(implode(' ', array_filter([
            $facts['requirements'] ?? '',
            $facts['other'] ?? '',
            $facts['reason_for_contact'] ?? '',
        ])));

        $keywords = [
            'логист' => 'логистика',
            'мебел' => 'мебель',
            'строит' => 'строительство',
            'it ' => 'IT',
            'авто' => 'авто',
            'медиц' => 'медицина',
            'образован' => 'образование',
        ];

        foreach ($keywords as $needle => $label) {
            if (str_contains($haystack, $needle)) {
                return $label;
            }
        }

        return null;
    }

    private function syncOutcomeTag(Chat $chat, bool $won): void
    {
        if ($chat->contact_id === null) {
            return;
        }

        $prefix = 'исход: ';
        $tagName = $won ? $prefix.'выигран' : $prefix.'проигран';

        ContactTag::query()
            ->where('company_id', $chat->company_id)
            ->where('contact_id', $chat->contact_id)
            ->where('source', ContactTag::SOURCE_AI)
            ->where('name', 'like', $prefix.'%')
            ->delete();

        ContactTag::query()->firstOrCreate(
            [
                'company_id' => $chat->company_id,
                'contact_id' => $chat->contact_id,
                'name' => $tagName,
            ],
            ['source' => ContactTag::SOURCE_AI],
        );
    }
}
