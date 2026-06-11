<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Jobs\AnalyzeChatFunnelJob;
use App\Jobs\ExtractConversationMemoryJob;
use App\Jobs\GenerateAiReplyJob;
use App\Jobs\RunAiFunnelOrchestratorJob;
use App\Models\FunnelAiScenario;
use App\Models\Message;
use App\Models\SystemSetting;
use App\Support\AiFeatureFlags;

final class InboundAiDispatchService
{
    public function __construct(
        private readonly ChatIdleAiReplyService $idleAiReply,
        private readonly ChatConflictService $conflictService,
    ) {}

    public function dispatchForInboundMessage(Message $message): void
    {
        $message->loadMissing('chat');
        $chat = $message->chat;

        if ($chat === null || $message->direction !== 'inbound') {
            return;
        }

        if ($this->conflictService->isAiPausedForConflict($chat)) {
            return;
        }

        $shouldAnalyzeFunnel = SystemSetting::getValue('module_funnels', 'on') === 'on'
            && ! $chat->is_group
            && $chat->funnel_tracking_enabled
            && ! $chat->funnel_stage_locked;

        $orchestratorEnabled = $chat->funnel_id !== null
            && FunnelAiScenario::query()
                ->where('funnel_id', $chat->funnel_id)
                ->where('company_id', $chat->company_id)
                ->where('enabled', true)
                ->exists();

        // Debounced memory extraction — must run on ALL paths including orchestrator.
        // Previously this block was after the orchestrator early-return, making it
        // unreachable for funnel-orchestrator tenants (EntityMemory never updated).
        if (AiFeatureFlags::enabled(AiFeatureFlags::MEMORY_EXTRACTION, $chat->company_id)
            && $chat->contact_id !== null
        ) {
            ExtractConversationMemoryJob::dispatchDebounced($chat->id, $chat->company_id);
        }

        if ($orchestratorEnabled && $chat->ai_enabled) {
            $delaySeconds = max(1, (int) config('funnel.orchestrator.debounce_seconds', 3));
            RunAiFunnelOrchestratorJob::dispatch($chat->id, $message->id, $chat->company_id)
                ->delay(now()->addSeconds($delaySeconds));

            // F5: run the standalone funnel classifier as a second opinion alongside the
            // orchestrator when the funnel sequence guard flag is enabled.  The classifier
            // result is recorded in the chat's funnel transition log so divergences can be
            // detected; it does NOT override the orchestrator's decision.
            if ($shouldAnalyzeFunnel
                && AiFeatureFlags::enabled(AiFeatureFlags::FUNNEL_SEQUENCE_GUARD, $chat->company_id)
            ) {
                $classifierDelay = max(1, (int) config('funnel.ai.debounce_seconds', 5));
                AnalyzeChatFunnelJob::dispatch($chat->id, $message->id, $chat->company_id)
                    ->delay(now()->addSeconds($classifierDelay));
            }

            return;
        }

        if ($shouldAnalyzeFunnel && $chat->ai_enabled) {
            $delaySeconds = max(1, (int) config('funnel.ai.debounce_seconds', 5));
            AnalyzeChatFunnelJob::dispatch($chat->id, $message->id, $chat->company_id)
                ->delay(now()->addSeconds($delaySeconds));
        }

        if ($chat->ai_enabled && ! $shouldAnalyzeFunnel) {
            $this->idleAiReply->dispatchGenerateReply($chat, $message->id);
        }
    }
}
