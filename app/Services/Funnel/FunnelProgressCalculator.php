<?php

declare(strict_types=1);

namespace App\Services\Funnel;

use App\Models\Chat;
use App\Models\FunnelStage;

final class FunnelProgressCalculator
{
    public function __construct(
        private readonly ChatFunnelCatalogBuilder $catalogBuilder,
    ) {}

    /**
     * @return array{percent: float, stage_index: int|null, stages_count: int}
     */
    public function forChat(Chat $chat): array
    {
        if ($chat->funnel_stage_id === null || $chat->funnel_id === null) {
            return ['percent' => 0.0, 'stage_index' => null, 'stages_count' => 0];
        }

        $catalog = $this->catalogBuilder->forChat($chat);
        foreach ($catalog as $funnel) {
            if ($funnel['id'] !== $chat->funnel_id) {
                continue;
            }
            $stages = $funnel['stages'];
            $count = count($stages);
            if ($count === 0) {
                return ['percent' => 0.0, 'stage_index' => null, 'stages_count' => 0];
            }
            foreach ($stages as $index => $stage) {
                if ($stage['id'] === $chat->funnel_stage_id) {
                    return [
                        'percent' => round((($index + 1) / $count) * 100, 1),
                        'stage_index' => $index,
                        'stages_count' => $count,
                    ];
                }
            }
        }

        $stage = FunnelStage::query()->whereKey($chat->funnel_stage_id)->first();
        if ($stage === null) {
            return ['percent' => 0.0, 'stage_index' => null, 'stages_count' => 0];
        }

        $count = max(1, (int) $stage->funnel?->stages()->where('is_active', true)->count());
        $index = max(0, (int) $stage->position);

        return [
            'percent' => round((($index + 1) / max(1, $count)) * 100, 1),
            'stage_index' => min($index, $count - 1),
            'stages_count' => $count,
        ];
    }
}
