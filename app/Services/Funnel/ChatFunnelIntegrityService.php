<?php

declare(strict_types=1);

namespace App\Services\Funnel;

use App\Models\Chat;
use App\Models\Funnel;
use App\Models\FunnelStage;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class ChatFunnelIntegrityService
{
    public function __construct(
        private readonly ChatFunnelCatalogBuilder $catalogBuilder,
    ) {}

    /**
     * @return Collection<int, Chat>
     */
    public function findMismatchedChats(): Collection
    {
        $chatIds = DB::table('chats')
            ->join('funnels', 'funnels.id', '=', 'chats.funnel_id')
            ->whereNotNull('chats.funnel_id')
            ->whereColumn('chats.company_id', '!=', 'funnels.company_id')
            ->pluck('chats.id');

        if ($chatIds->isEmpty()) {
            return collect();
        }

        return Chat::query()
            ->withoutGlobalScope('tenant')
            ->whereIn('id', $chatIds)
            ->get();
    }

    public function repair(Chat $chat): bool
    {
        if ($chat->company_id === null || $chat->funnel_id === null) {
            return false;
        }

        $currentFunnel = Funnel::query()
            ->withoutGlobalScope('tenant')
            ->whereKey($chat->funnel_id)
            ->first();

        if ($currentFunnel === null || (int) $currentFunnel->company_id === (int) $chat->company_id) {
            return false;
        }

        $stageName = null;
        if ($chat->funnel_stage_id !== null) {
            $stageName = FunnelStage::query()
                ->withoutGlobalScope('tenant')
                ->whereKey($chat->funnel_stage_id)
                ->value('name');
        }

        $targetFunnel = Funnel::query()
            ->withoutGlobalScope('tenant')
            ->where('company_id', $chat->company_id)
            ->where('is_active', true)
            ->where('name', $currentFunnel->name)
            ->orderBy('id')
            ->first();

        if ($targetFunnel === null) {
            $targetFunnel = Funnel::query()
                ->withoutGlobalScope('tenant')
                ->where('company_id', $chat->company_id)
                ->where('is_active', true)
                ->orderBy('position')
                ->orderBy('id')
                ->first();
        }

        if ($targetFunnel === null) {
            Log::warning('[funnel-integrity] no replacement funnel for chat', [
                'chat_id' => $chat->id,
                'company_id' => $chat->company_id,
                'foreign_funnel_id' => $chat->funnel_id,
            ]);

            return false;
        }

        $targetStageId = $this->resolveTargetStageId($targetFunnel, $stageName, $chat);

        $chat->forceFill([
            'funnel_id' => $targetFunnel->id,
            'funnel_stage_id' => $targetStageId,
        ])->save();

        Log::info('[funnel-integrity] repaired cross-tenant funnel assignment', [
            'chat_id' => $chat->id,
            'company_id' => $chat->company_id,
            'from_funnel_id' => $currentFunnel->id,
            'to_funnel_id' => $targetFunnel->id,
            'to_stage_id' => $targetStageId,
        ]);

        return true;
    }

    /**
     * @return array{scanned: int, repaired: int}
     */
    public function repairAll(): array
    {
        $chats = $this->findMismatchedChats();
        $repaired = 0;

        foreach ($chats as $chat) {
            if ($this->repair($chat)) {
                $repaired++;
            }
        }

        return [
            'scanned' => $chats->count(),
            'repaired' => $repaired,
        ];
    }

    private function resolveTargetStageId(Funnel $targetFunnel, ?string $stageName, Chat $chat): ?int
    {
        $catalog = $this->catalogBuilder->forChat($chat->fresh() ?? $chat);

        if ($stageName !== null && $stageName !== '') {
            $byName = FunnelStage::query()
                ->withoutGlobalScope('tenant')
                ->where('funnel_id', $targetFunnel->id)
                ->where('is_active', true)
                ->where('name', $stageName)
                ->value('id');

            if (is_int($byName) && $this->catalogBuilder->isPairInCatalog($catalog, $targetFunnel->id, $byName)) {
                return $byName;
            }
        }

        $mapped = $this->catalogBuilder->mapStagePreservingIndex(
            $catalog,
            $chat->funnel_id !== null ? (int) $chat->funnel_id : null,
            $chat->funnel_stage_id !== null ? (int) $chat->funnel_stage_id : null,
            $targetFunnel->id,
        );

        if ($mapped !== null && $this->catalogBuilder->isPairInCatalog($catalog, $targetFunnel->id, $mapped)) {
            return $mapped;
        }

        $firstStage = FunnelStage::query()
            ->withoutGlobalScope('tenant')
            ->where('funnel_id', $targetFunnel->id)
            ->where('is_active', true)
            ->orderBy('position')
            ->orderBy('id')
            ->value('id');

        return is_int($firstStage) ? $firstStage : null;
    }
}
