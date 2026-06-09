<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Jobs\AnalyzeChatFunnelJob;
use App\Jobs\GenerateAiReplyJob;
use App\Jobs\RunAiFunnelOrchestratorJob;
use App\Models\FunnelAiScenario;
use App\Models\Message;
use App\Models\SystemSetting;

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

        if ($orchestratorEnabled && $chat->ai_enabled) {
            $delaySeconds = max(1, (int) config('funnel.orchestrator.debounce_seconds', 3));
            RunAiFunnelOrchestratorJob::dispatch($chat->id, $message->id, $chat->company_id)
                ->delay(now()->addSeconds($delaySeconds));

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
