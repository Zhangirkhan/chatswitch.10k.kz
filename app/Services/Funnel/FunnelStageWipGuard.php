<?php

declare(strict_types=1);

namespace App\Services\Funnel;

use App\Models\Chat;
use App\Models\FunnelStage;

final class FunnelStageWipGuard
{
    public function assertCanAccept(int $stageId, int $chatId): void
    {
        $stage = FunnelStage::query()->find($stageId);
        if ($stage === null || $stage->wip_limit === null) {
            return;
        }

        $count = Chat::query()
            ->where('funnel_id', $stage->funnel_id)
            ->where('funnel_stage_id', $stage->id)
            ->where('is_group', false)
            ->where('is_archived', false)
            ->whereKeyNot($chatId)
            ->count();

        if ($count >= (int) $stage->wip_limit) {
            abort(422, "Лимит WIP на этапе «{$stage->name}»: {$stage->wip_limit}");
        }
    }
}
