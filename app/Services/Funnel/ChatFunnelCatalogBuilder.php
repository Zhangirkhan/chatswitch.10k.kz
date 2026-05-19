<?php

declare(strict_types=1);

namespace App\Services\Funnel;

use App\Models\Chat;
use App\Models\Department;
use App\Models\Funnel;
use App\Models\FunnelStage;
use Illuminate\Support\Collection;

/**
 * Каталог воронок/этапов для чата: воронки отделов чата + этапы из department_funnel_stage
 * (если для воронки этапы не выбраны — все активные этапы воронки).
 */
final class ChatFunnelCatalogBuilder
{
    /**
     * @return list<array{
     *     id: int,
     *     name: string,
     *     description: string|null,
     *     color: string,
     *     stages: list<array{id: int, name: string, color: string, stage_type: string, position: int}>
     * }>
     */
    public function forChat(Chat $chat): array
    {
        $chat->loadMissing([
            'departments.funnels' => static fn ($q) => $q->where('is_active', true)->orderBy('position')->orderBy('id'),
            'departments.funnelStages' => static fn ($q) => $q->where('is_active', true),
        ]);

        /** @var Collection<int, Department> $departments */
        $departments = $chat->departments;
        if ($departments->isEmpty()) {
            return $this->companyCatalog($chat);
        }

        /** @var array<int, true> $funnelIds */
        $funnelIds = [];
        /** @var array<int, array<int, true>> $explicitStageIdsByFunnel */
        $explicitStageIdsByFunnel = [];

        foreach ($departments as $department) {
            foreach ($department->funnels as $funnel) {
                $funnelIds[$funnel->id] = true;
            }
            foreach ($department->funnelStages as $stage) {
                $explicitStageIdsByFunnel[$stage->funnel_id][$stage->id] = true;
            }
        }

        if ($funnelIds === []) {
            return $this->companyCatalog($chat);
        }

        /** @var Collection<int, Funnel> $funnels */
        $funnels = Funnel::query()
            ->whereIn('id', array_keys($funnelIds))
            ->where('is_active', true)
            ->with(['stages' => static fn ($q) => $q->where('is_active', true)->orderBy('position')->orderBy('id')])
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        $catalog = [];

        foreach ($funnels as $funnel) {
            $explicit = $explicitStageIdsByFunnel[$funnel->id] ?? null;

            /** @var Collection<int, FunnelStage> $stages */
            $stages = $funnel->stages;
            if ($explicit !== null && $explicit !== []) {
                $allowedIds = array_keys($explicit);
                $stages = $stages->whereIn('id', $allowedIds)->values();
            }

            if ($stages->isEmpty()) {
                continue;
            }

            $catalog[] = [
                'id' => $funnel->id,
                'name' => $funnel->name,
                'description' => $funnel->description,
                'color' => $funnel->color,
                'stages' => $stages
                    ->values()
                    ->map(static fn (FunnelStage $stage): array => [
                        'id' => $stage->id,
                        'name' => $stage->name,
                        'color' => $stage->color,
                        'stage_type' => $stage->stage_type,
                        'position' => $stage->position,
                    ])
                    ->all(),
            ];
        }

        return $catalog;
    }

    /**
     * When a chat is new it may not have departments yet. In that state the AI
     * still needs a catalog so it can pick the first suitable funnel itself.
     *
     * @return list<array{
     *     id: int,
     *     name: string,
     *     description: string|null,
     *     color: string,
     *     stages: list<array{id: int, name: string, color: string, stage_type: string, position: int}>
     * }>
     */
    private function companyCatalog(Chat $chat): array
    {
        if ($chat->company_id === null) {
            return [];
        }

        /** @var Collection<int, Funnel> $funnels */
        $funnels = Funnel::query()
            ->where('company_id', $chat->company_id)
            ->where('is_active', true)
            ->with(['stages' => static fn ($q) => $q->where('is_active', true)->orderBy('position')->orderBy('id')])
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        return $funnels
            ->filter(static fn (Funnel $funnel): bool => $funnel->stages->isNotEmpty())
            ->map(static fn (Funnel $funnel): array => [
                'id' => $funnel->id,
                'name' => $funnel->name,
                'description' => $funnel->description,
                'color' => $funnel->color,
                'stages' => $funnel->stages
                    ->values()
                    ->map(static fn (FunnelStage $stage): array => [
                        'id' => $stage->id,
                        'name' => $stage->name,
                        'color' => $stage->color,
                        'stage_type' => $stage->stage_type,
                        'position' => $stage->position,
                    ])
                    ->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  list<array{id: int, name: string, description: string|null, color: string, stages: list<array{id: int, name: string, color: string, position: int}>}>  $catalog
     */
    public function isPairInCatalog(array $catalog, int $funnelId, int $stageId): bool
    {
        foreach ($catalog as $funnel) {
            if ($funnel['id'] !== $funnelId) {
                continue;
            }
            foreach ($funnel['stages'] as $stage) {
                if ($stage['id'] === $stageId) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * При смене воронки подбирает этап с тем же порядковым индексом (0-based по position).
     *
     * @param  list<array{id: int, name: string, description: string|null, color: string, stages: list<array{id: int, name: string, color: string, stage_type?: string, position: int}>}>  $catalog
     */
    public function mapStagePreservingIndex(
        array $catalog,
        ?int $fromFunnelId,
        ?int $fromStageId,
        int $toFunnelId,
    ): ?int {
        $toStages = $this->orderedStagesForFunnel($catalog, $toFunnelId);
        if ($toStages === []) {
            return null;
        }

        $fromIndex = $this->stageIndexInFunnel($catalog, $fromFunnelId, $fromStageId);
        $targetIndex = $fromIndex >= 0 ? $fromIndex : 0;

        return $toStages[min($targetIndex, count($toStages) - 1)]['id'];
    }

    /**
     * @param  list<array{id: int, stages: list<array{id: int, position: int}>}>  $catalog
     * @return list<array{id: int, position: int}>
     */
    private function orderedStagesForFunnel(array $catalog, int $funnelId): array
    {
        foreach ($catalog as $funnel) {
            if ($funnel['id'] !== $funnelId) {
                continue;
            }

            $stages = $funnel['stages'];
            usort($stages, static fn (array $a, array $b): int => $a['position'] <=> $b['position'] ?: $a['id'] <=> $b['id']);

            return $stages;
        }

        return [];
    }

    /**
     * @param  list<array{id: int, stages: list<array{id: int, position: int}>}>  $catalog
     */
    private function stageIndexInFunnel(array $catalog, ?int $funnelId, ?int $stageId): int
    {
        if ($funnelId === null || $stageId === null) {
            return -1;
        }

        $stages = $this->orderedStagesForFunnel($catalog, $funnelId);
        foreach ($stages as $index => $stage) {
            if ($stage['id'] === $stageId) {
                return $index;
            }
        }

        return -1;
    }
}
