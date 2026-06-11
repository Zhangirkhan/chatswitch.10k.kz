<?php

declare(strict_types=1);

namespace App\Services\Funnel;

use App\Models\Chat;
use App\Support\AiFeatureFlags;

/**
 * Единые правила смены этапа воронки (каталог, откат назад, порог уверенности).
 *
 * With ai.funnel_sequence_guard enabled:
 *  - forward jumps of more than funnel.ai.max_skip_stages (default 2) are blocked
 *    unless confidence is above funnel.ai.skip_stages_min_confidence (default 0.90)
 *  - WIP limit is enforced even for AI-initiated transitions
 */
final class FunnelStageTransitionGuard
{
    public function __construct(
        private readonly ChatFunnelCatalogBuilder $catalogBuilder,
    ) {}

    /**
     * @return null если переход допустим, иначе код причины отказа
     */
    public function rejectReason(Chat $chat, int $funnelId, int $stageId, float $confidence): ?string
    {
        $catalog = $this->catalogBuilder->forChat($chat);

        if (! $this->catalogBuilder->isPairInCatalog($catalog, $funnelId, $stageId)) {
            return 'invalid_catalog_pair';
        }

        if ((int) $chat->funnel_id === $funnelId && (int) $chat->funnel_stage_id === $stageId) {
            return 'already_on_stage';
        }

        if ($this->isRollbackBlocked($chat, $catalog, $funnelId, $stageId, $confidence)) {
            return 'rollback_blocked';
        }

        // Sequence guard (requires ai.funnel_sequence_guard feature flag).
        if (AiFeatureFlags::enabled(AiFeatureFlags::FUNNEL_SEQUENCE_GUARD, $chat->company_id)) {
            if ($this->isForwardSkipBlocked($chat, $catalog, $funnelId, $stageId, $confidence)) {
                return 'forward_skip_blocked';
            }
        }

        return null;
    }

    public function canMove(Chat $chat, int $funnelId, int $stageId, float $confidence): bool
    {
        return $this->rejectReason($chat, $funnelId, $stageId, $confidence) === null;
    }

    /**
     * @param  list<array{id: int, name: string, description: string|null, color: string, stages: list<array{id: int, name: string, color: string, position: int}>}>  $catalog
     */
    private function isRollbackBlocked(Chat $chat, array $catalog, int $newFunnelId, int $newStageId, float $confidence): bool
    {
        if ($chat->funnel_id === null || $chat->funnel_stage_id === null) {
            return false;
        }

        if ((int) $chat->funnel_id !== $newFunnelId) {
            return false;
        }

        $oldIdx = $this->stageIndexInCatalog($catalog, $newFunnelId, (int) $chat->funnel_stage_id);
        $newIdx = $this->stageIndexInCatalog($catalog, $newFunnelId, $newStageId);
        if ($oldIdx === null || $newIdx === null) {
            return false;
        }

        if ($newIdx >= $oldIdx) {
            return false;
        }

        $rollbackMin = (float) config('funnel.ai.rollback_min_confidence', 0.85);

        return $confidence < $rollbackMin;
    }

    /**
     * Block forward jumps that skip more stages than allowed.
     * High-confidence plans (e.g. ≥ 0.90) may override the guard.
     *
     * @param  list<array{id: int, name: string, description: string|null, color: string, stages: list<array{id: int, name: string, color: string, position: int}>}>  $catalog
     */
    private function isForwardSkipBlocked(Chat $chat, array $catalog, int $newFunnelId, int $newStageId, float $confidence): bool
    {
        if ($chat->funnel_id === null || $chat->funnel_stage_id === null) {
            return false;
        }

        if ((int) $chat->funnel_id !== $newFunnelId) {
            return false;
        }

        $oldIdx = $this->stageIndexInCatalog($catalog, $newFunnelId, (int) $chat->funnel_stage_id);
        $newIdx = $this->stageIndexInCatalog($catalog, $newFunnelId, $newStageId);

        if ($oldIdx === null || $newIdx === null) {
            return false;
        }

        // Only applies to forward moves.
        if ($newIdx <= $oldIdx) {
            return false;
        }

        $maxSkip = (int) config('funnel.ai.max_skip_stages', 2);
        $skipCount = $newIdx - $oldIdx;

        if ($skipCount <= $maxSkip) {
            return false;
        }

        // High-confidence plans may override the skip limit.
        $skipOverrideConfidence = (float) config('funnel.ai.skip_stages_min_confidence', 0.90);

        return $confidence < $skipOverrideConfidence;
    }

    /**
     * @param  list<array{id: int, name: string, description: string|null, color: string, stages: list<array{id: int, name: string, color: string, position: int}>}>  $catalog
     */
    private function stageIndexInCatalog(array $catalog, int $funnelId, int $stageId): ?int
    {
        foreach ($catalog as $funnel) {
            if ($funnel['id'] !== $funnelId) {
                continue;
            }
            foreach ($funnel['stages'] as $index => $stage) {
                if ($stage['id'] === $stageId) {
                    return $index;
                }
            }
        }

        return null;
    }
}
