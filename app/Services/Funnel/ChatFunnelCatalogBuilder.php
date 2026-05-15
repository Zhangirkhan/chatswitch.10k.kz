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
     *     stages: list<array{id: int, name: string, color: string, position: int}>
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
            return [];
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
            return [];
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
                        'position' => $stage->position,
                    ])
                    ->all(),
            ];
        }

        return $catalog;
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
}
