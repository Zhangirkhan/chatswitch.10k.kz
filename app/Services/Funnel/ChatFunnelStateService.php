<?php

declare(strict_types=1);

namespace App\Services\Funnel;

use App\Events\ChatFunnelUpdated;
use App\Models\Chat;
use App\Models\ChatFunnelTransition;
use App\Models\User;
use App\Services\AI\ChatFunnelClassification;
use Illuminate\Support\Facades\DB;

final class ChatFunnelStateService
{
    public function __construct(
        private readonly ChatFunnelCatalogBuilder $catalogBuilder,
        private readonly FunnelProgressCalculator $progressCalculator,
    ) {}

    public function applyFromAi(Chat $chat, ChatFunnelClassification $classification, int $triggerMessageId): void
    {
        $fromFunnelId = $chat->funnel_id;
        $fromStageId = $chat->funnel_stage_id;

        DB::transaction(function () use ($chat, $classification, $triggerMessageId, $fromFunnelId, $fromStageId): void {
            $chat->forceFill([
                'funnel_id' => $classification->funnelId,
                'funnel_stage_id' => $classification->funnelStageId,
                'funnel_ai_last_analyzed_at' => now(),
                'funnel_ai_last_message_id' => $triggerMessageId,
                'funnel_ai_last_reason' => $classification->reason,
            ])->save();

            ChatFunnelTransition::query()->create([
                'chat_id' => $chat->id,
                'company_id' => $chat->company_id,
                'from_funnel_id' => $fromFunnelId,
                'from_stage_id' => $fromStageId,
                'to_funnel_id' => $classification->funnelId,
                'to_stage_id' => $classification->funnelStageId,
                'source' => ChatFunnelTransition::SOURCE_AI,
                'confidence' => $classification->confidence,
                'reason' => $classification->reason,
                'trigger_message_id' => $triggerMessageId,
            ]);
        });

        if ($fromStageId !== $classification->funnelStageId) {
            app(FunnelStageFollowUpService::class)->cancelPendingForChat($chat->fresh() ?? $chat);
        }

        $this->broadcastFresh($chat->id, 'ai', $classification->reason);
    }

    /**
     * @param  array{funnel_id?: int|null, funnel_stage_id?: int|null, funnel_tracking_enabled?: bool, funnel_stage_locked?: bool}  $data
     */
    public function applyManual(Chat $chat, array $data, User $actor): void
    {
        $catalog = $this->catalogBuilder->forChat($chat);

        $fromFunnelId = $chat->funnel_id;
        $fromStageId = $chat->funnel_stage_id;

        $funnelId = array_key_exists('funnel_id', $data) ? $data['funnel_id'] : $chat->funnel_id;
        $stageId = array_key_exists('funnel_stage_id', $data) ? $data['funnel_stage_id'] : $chat->funnel_stage_id;

        if ($funnelId !== null && $funnelId !== '' && $stageId !== null && $stageId !== '') {
            $funnelId = (int) $funnelId;
            $stageId = (int) $stageId;
            if (! $this->catalogBuilder->isPairInCatalog($catalog, $funnelId, $stageId)) {
                $mappedStageId = $this->catalogBuilder->mapStagePreservingIndex(
                    $catalog,
                    $fromFunnelId !== null ? (int) $fromFunnelId : null,
                    $fromStageId !== null ? (int) $fromStageId : null,
                    $funnelId,
                );
                if ($mappedStageId !== null && $this->catalogBuilder->isPairInCatalog($catalog, $funnelId, $mappedStageId)) {
                    $stageId = $mappedStageId;
                }
            }
            if (! $this->catalogBuilder->isPairInCatalog($catalog, $funnelId, $stageId)) {
                abort(422, 'Выбранная воронка или этап не доступны для этого чата (отделы и настройки воронок).');
            }
        } else {
            $funnelId = null;
            $stageId = null;
        }

        $tracking = array_key_exists('funnel_tracking_enabled', $data)
            ? (bool) $data['funnel_tracking_enabled']
            : $chat->funnel_tracking_enabled;
        $locked = array_key_exists('funnel_stage_locked', $data)
            ? (bool) $data['funnel_stage_locked']
            : $chat->funnel_stage_locked;

        $funnelChanged = $fromFunnelId != $funnelId || $fromStageId != $stageId;

        DB::transaction(function () use (
            $chat,
            $funnelId,
            $stageId,
            $tracking,
            $locked,
            $fromFunnelId,
            $fromStageId,
            $funnelChanged,
            $actor,
        ): void {
            $chat->forceFill([
                'funnel_id' => $funnelId,
                'funnel_stage_id' => $stageId,
                'funnel_tracking_enabled' => $tracking,
                'funnel_stage_locked' => $locked,
            ])->save();

            if ($funnelChanged) {
                $reason = 'Изменено вручную: '.$actor->name;
                ChatFunnelTransition::query()->create([
                    'chat_id' => $chat->id,
                    'company_id' => $chat->company_id,
                    'from_funnel_id' => $fromFunnelId,
                    'from_stage_id' => $fromStageId,
                    'to_funnel_id' => $funnelId,
                    'to_stage_id' => $stageId,
                    'source' => ChatFunnelTransition::SOURCE_MANUAL,
                    'confidence' => null,
                    'reason' => $reason,
                    'trigger_message_id' => null,
                ]);
            }
        });

        if ($funnelChanged) {
            app(FunnelStageFollowUpService::class)->cancelPendingForChat($chat->fresh() ?? $chat);
        }

        $this->broadcastFresh($chat->id, 'manual');
    }

    private function broadcastFresh(int $chatId, string $source = 'sync', ?string $reasonOverride = null): void
    {
        $chat = Chat::query()
            ->with(['funnel', 'funnelStage'])
            ->whereKey($chatId)
            ->first();

        if ($chat === null) {
            return;
        }

        $progress = $this->progressCalculator->forChat($chat);

        $payload = [
            'funnel' => $chat->funnel ? [
                'id' => $chat->funnel->id,
                'name' => $chat->funnel->name,
                'color' => $chat->funnel->color,
            ] : null,
            'stage' => $chat->funnelStage ? [
                'id' => $chat->funnelStage->id,
                'name' => $chat->funnelStage->name,
                'color' => $chat->funnelStage->color,
                'stage_type' => $chat->funnelStage->stage_type,
                'position' => $chat->funnelStage->position,
            ] : null,
            'progress_percent' => $progress['percent'],
            'funnel_progress' => $progress,
            'reason' => $reasonOverride ?? $chat->funnel_ai_last_reason,
            'source' => $source,
            'funnel_tracking_enabled' => $chat->funnel_tracking_enabled,
            'funnel_stage_locked' => $chat->funnel_stage_locked,
        ];

        broadcast(new ChatFunnelUpdated($chatId, $payload));
    }

    /**
     * @return array{funnel: array{id: int, name: string, color: string}|null, stage: array{id: int, name: string, color: string, position: int}|null, progress_percent: float, funnel_progress: array{percent: float, stage_index: int|null, stages_count: int}}
     */
    public function inertiaExtras(Chat $chat): array
    {
        $chat->loadMissing(['funnel', 'funnelStage']);
        $progress = $this->progressCalculator->forChat($chat);

        return [
            'funnel' => $chat->funnel ? $chat->funnel->only(['id', 'name', 'color']) : null,
            'funnel_stage' => $chat->funnelStage ? $chat->funnelStage->only(['id', 'name', 'color', 'stage_type', 'position']) : null,
            'funnel_progress_percent' => $progress['percent'],
            'funnel_progress' => $progress,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function catalogForClient(Chat $chat): array
    {
        return $this->catalogBuilder->forChat($chat);
    }
}
