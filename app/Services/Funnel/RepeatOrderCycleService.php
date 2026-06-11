<?php

declare(strict_types=1);

namespace App\Services\Funnel;

use App\Models\Chat;
use App\Models\FunnelStage;
use App\Models\Message;
use App\Services\AI\ChatSalesStateService;
use App\Services\AI\Orchestrator\ClientMessageIntentDetector;
use App\Services\Chat\ChatLeadClosureService;
use App\Support\FunnelStageType;
use App\Support\MessageInboundText;
use Illuminate\Support\Facades\Log;

/**
 * When a client starts a new order after the previous deal reached closure,
 * restart the funnel cycle on an early stage and reset sales state.
 */
final class RepeatOrderCycleService
{
    public function __construct(
        private readonly ClientMessageIntentDetector $intents,
        private readonly ChatFunnelStateService $funnelState,
        private readonly ChatSalesStateService $salesState,
        private readonly ChatLeadClosureService $leadClosure,
    ) {}

    public function restartIfNeeded(Chat $chat, Message $message): bool
    {
        if ($message->direction !== 'inbound' || ! $chat->funnel_tracking_enabled || $chat->funnel_stage_locked) {
            return false;
        }

        $body = trim(MessageInboundText::forMessage($message));
        if ($body === '' || ! $this->intents->isRepeatOrderIntent($body)) {
            return false;
        }

        if (! $this->isChatAtClosureStage($chat)) {
            return false;
        }

        $targetStageId = $this->resolveTargetStageId($chat);
        if ($targetStageId === null || (int) $chat->funnel_stage_id === $targetStageId) {
            return false;
        }

        $this->leadClosure->reopenAfterInboundIfNeeded($chat);

        $this->funnelState->applySystemTransition(
            $chat,
            $targetStageId,
            $message->id,
            'Клиент оформляет повторный заказ — новый цикл воронки.',
        );

        $this->salesState->resetForNewOrderCycle($chat);

        $chat->forceFill([
            'ai_orchestrator_status' => null,
            'ai_orchestrator_last_summary' => null,
        ])->save();

        Log::info('[repeat-order] funnel cycle restarted', [
            'chat_id' => $chat->id,
            'message_id' => $message->id,
            'target_stage_id' => $targetStageId,
        ]);

        return true;
    }

    public function isChatAtClosureStage(Chat $chat): bool
    {
        if ($chat->is_lead_closed) {
            return true;
        }

        $chat->loadMissing('funnelStage');
        $stage = $chat->funnelStage;
        if ($stage === null) {
            return false;
        }

        $name = mb_strtolower(trim($stage->name));
        if ($name === '') {
            return false;
        }

        if (FunnelStageType::guessFromName($stage->name) === FunnelStageType::DONE) {
            return true;
        }

        return str_contains($name, 'закрыт');
    }

    public function resolveTargetStageId(Chat $chat): ?int
    {
        $funnelId = (int) ($chat->funnel_id ?? 0);
        if ($funnelId <= 0) {
            return null;
        }

        $stages = FunnelStage::query()
            ->where('funnel_id', $funnelId)
            ->where('is_active', true)
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        if ($stages->isEmpty()) {
            return null;
        }

        foreach ($stages as $stage) {
            $name = mb_strtolower(trim($stage->name));
            if (str_contains($name, 'уточнен') || str_contains($name, 'новый заказ')) {
                return (int) $stage->id;
            }
        }

        foreach ($stages as $stage) {
            if (FunnelStageType::guessFromName($stage->name) === FunnelStageType::QUALIFICATION) {
                return (int) $stage->id;
            }
        }

        foreach ($stages as $stage) {
            if (FunnelStageType::guessFromName($stage->name) === FunnelStageType::LEAD) {
                return (int) $stage->id;
            }
        }

        foreach ($stages as $stage) {
            if (FunnelStageType::guessFromName($stage->name) !== FunnelStageType::DONE) {
                return (int) $stage->id;
            }
        }

        return (int) $stages->first()->id;
    }
}
