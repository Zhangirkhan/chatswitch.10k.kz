<?php

declare(strict_types=1);

namespace App\Services\Funnel;

use App\Models\FunnelStage;
use App\Models\FunnelStageAiRule;

final class FunnelStageSequenceService
{
    public function nextStage(?FunnelStage $current): ?FunnelStage
    {
        if ($current === null) {
            return null;
        }

        $current->loadMissing(['funnel.stages']);

        $funnel = $current->funnel;
        if ($funnel === null) {
            return null;
        }

        $next = $funnel->stages
            ->where('is_active', true)
            ->where('position', '>', (int) $current->position)
            ->sortBy('position')
            ->first();

        return $next instanceof FunnelStage ? $next : null;
    }

    public function nextStageForRule(FunnelStageAiRule $rule): ?FunnelStage
    {
        $rule->loadMissing(['stage.funnel.stages']);

        return $this->nextStage($rule->stage);
    }

    public function nextStageRule(FunnelStageAiRule $rule): ?FunnelStageAiRule
    {
        $nextStage = $this->nextStageForRule($rule);
        if ($nextStage === null) {
            return null;
        }

        return FunnelStageAiRule::query()
            ->where('funnel_stage_id', $nextStage->id)
            ->first();
    }
}
