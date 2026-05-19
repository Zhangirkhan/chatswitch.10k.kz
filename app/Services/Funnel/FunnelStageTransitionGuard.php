<?php

declare(strict_types=1);

namespace App\Services\Funnel;

use App\Models\Chat;

/**
 * Единые правила смены этапа воронки (каталог, откат назад, порог уверенности).
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
