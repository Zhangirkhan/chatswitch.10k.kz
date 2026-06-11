<?php

declare(strict_types=1);

namespace App\Services\Funnel;

use App\Events\ChatFunnelUpdated;
use App\Events\FunnelBoardCardUpdated;
use App\Models\Chat;
use App\Models\ChatFunnelTransition;
use App\Models\User;
use App\Services\AI\ChatFunnelClassification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Services\Funnel\EvidenceTransitionGate;
use App\Services\Funnel\FunnelStageTransitionGuard;

final class ChatFunnelStateService
{
    public function __construct(
        private readonly ChatFunnelCatalogBuilder $catalogBuilder,
        private readonly FunnelProgressCalculator $progressCalculator,
        private readonly FunnelStageTransitionGuard $transitionGuard,
        private readonly EvidenceTransitionGate $evidenceGate,
    ) {}

    public function applyFromAi(Chat $chat, ChatFunnelClassification $classification, int $triggerMessageId): void
    {
        $catalog = $this->catalogBuilder->forChat($chat);
        if (! $this->catalogBuilder->isPairInCatalog($catalog, $classification->funnelId, $classification->funnelStageId)) {
            Log::warning('[funnel-ai] rejected cross-tenant or invalid funnel assignment', [
                'chat_id' => $chat->id,
                'company_id' => $chat->company_id,
                'funnel_id' => $classification->funnelId,
                'funnel_stage_id' => $classification->funnelStageId,
            ]);

            return;
        }

        // Apply forward-skip and rollback transition guard (same guard used by orchestrator).
        $rejectReason = $this->transitionGuard->rejectReason(
            $chat,
            $classification->funnelId,
            $classification->funnelStageId,
            $classification->confidence,
        );

        if ($rejectReason !== null && $rejectReason !== 'already_on_stage') {
            Log::info('[funnel-ai] transition guard blocked applyFromAi', [
                'chat_id' => $chat->id,
                'funnel_id' => $classification->funnelId,
                'funnel_stage_id' => $classification->funnelStageId,
                'confidence' => $classification->confidence,
                'reason' => $rejectReason,
            ]);

            return;
        }

        // Evidence gate: block advancement into qualification/negotiation/closing stages
        // when the required evidence (budget, qualification, agreements) is not recorded.
        $evidenceReject = $this->evidenceGate->rejectReason($chat, $classification->funnelStageId);
        if ($evidenceReject !== null) {
            Log::info('[funnel-ai] evidence gate blocked applyFromAi', [
                'chat_id'           => $chat->id,
                'funnel_stage_id'   => $classification->funnelStageId,
                'evidence_reason'   => $evidenceReject,
            ]);

            return;
        }

        // Apply WIP guard for AI-initiated transitions (mirrors applyManual behaviour).
        $stageChanged = (int) $chat->funnel_stage_id !== $classification->funnelStageId;
        if ($stageChanged && $classification->funnelStageId > 0) {
            try {
                app(FunnelStageWipGuard::class)->assertCanAccept(
                    $classification->funnelStageId,
                    (int) $chat->id,
                );
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
                Log::info('[funnel-ai] WIP guard blocked AI transition', [
                    'chat_id' => $chat->id,
                    'funnel_stage_id' => $classification->funnelStageId,
                    'wip_message' => $e->getMessage(),
                ]);

                return;
            }
        }

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

            $targetStage = \App\Models\FunnelStage::query()->find($classification->funnelStageId);
            if ($targetStage !== null) {
                app(\App\Services\AI\DealOutcomeRecorder::class)->recordFromStageTransition(
                    $chat->fresh() ?? $chat,
                    $targetStage,
                );
            }
        }

        $this->broadcastFresh($chat->id, 'ai', $classification->reason);
    }

    /**
     * System-initiated transition (e.g. repeat order cycle) — bypasses AI transition guards.
     */
    public function applySystemTransition(
        Chat $chat,
        int $targetStageId,
        int $triggerMessageId,
        string $reason,
    ): void {
        $funnelId = (int) ($chat->funnel_id ?? 0);
        if ($funnelId <= 0) {
            return;
        }

        $catalog = $this->catalogBuilder->forChat($chat);
        if (! $this->catalogBuilder->isPairInCatalog($catalog, $funnelId, $targetStageId)) {
            Log::warning('[funnel-system] rejected invalid stage for repeat order restart', [
                'chat_id' => $chat->id,
                'funnel_id' => $funnelId,
                'target_stage_id' => $targetStageId,
            ]);

            return;
        }

        $fromFunnelId = $chat->funnel_id;
        $fromStageId = $chat->funnel_stage_id;

        if ((int) $fromStageId === $targetStageId) {
            return;
        }

        DB::transaction(function () use (
            $chat,
            $funnelId,
            $targetStageId,
            $triggerMessageId,
            $reason,
            $fromFunnelId,
            $fromStageId,
        ): void {
            $chat->forceFill([
                'funnel_id' => $funnelId,
                'funnel_stage_id' => $targetStageId,
                'funnel_ai_last_analyzed_at' => now(),
                'funnel_ai_last_message_id' => $triggerMessageId,
                'funnel_ai_last_reason' => $reason,
            ])->save();

            ChatFunnelTransition::query()->create([
                'chat_id' => $chat->id,
                'company_id' => $chat->company_id,
                'from_funnel_id' => $fromFunnelId,
                'from_stage_id' => $fromStageId,
                'to_funnel_id' => $funnelId,
                'to_stage_id' => $targetStageId,
                'source' => ChatFunnelTransition::SOURCE_SYSTEM,
                'confidence' => 1.0,
                'reason' => $reason,
                'trigger_message_id' => $triggerMessageId,
            ]);
        });

        app(FunnelStageFollowUpService::class)->cancelPendingForChat($chat->fresh() ?? $chat);
        $this->broadcastFresh($chat->id, 'system', $reason);
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

        if ($funnelChanged && $stageId !== null && (int) $fromStageId !== (int) $stageId) {
            app(FunnelStageWipGuard::class)->assertCanAccept((int) $stageId, (int) $chat->id);
        }

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

        $this->broadcastFresh($chat->id, 'manual', null, $actor->id, $actor->name);
    }

    private function broadcastFresh(
        int $chatId,
        string $source = 'sync',
        ?string $reasonOverride = null,
        ?int $actorUserId = null,
        ?string $actorName = null,
    ): void {
        $chat = Chat::query()
            ->with(['funnel', 'funnelStage', 'contact', 'assignments.user'])
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

        if ($chat->funnel_id !== null) {
            $boardCard = app(FunnelBoardService::class)->serializeCard($chat);
            broadcast(new FunnelBoardCardUpdated(
                (int) $chat->funnel_id,
                $chatId,
                $chat->funnel_stage_id !== null ? (int) $chat->funnel_stage_id : null,
                $actorUserId,
                $source,
                [
                    ...$boardCard,
                    'actor_name' => $actorName,
                ],
            ));
        }
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
