<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\AiOrchestratorRun;
use App\Models\Chat;
use App\Models\FunnelAiScenario;
use App\Models\Message;
use App\Models\SystemSetting;
use App\Services\AI\ActiveTopicDetector;
use App\Services\AI\ChatDepartmentRoutingService;
use App\Services\AI\ChatFunnelClassifierService;
use App\Services\AI\ChatIdleAiReplyService;
use App\Services\AI\ChatOffHoursReplyService;
use App\Services\Funnel\ChatFunnelStateService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

final class AnalyzeChatFunnelJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct(
        public readonly int $chatId,
        public readonly int $triggerMessageId,
        public readonly ?int $tenantCompanyId = null,
    ) {}

    public function handle(
        ChatFunnelClassifierService $classifier,
        ChatFunnelStateService $state,
        ChatDepartmentRoutingService $departmentRouting,
        ChatOffHoursReplyService $offHoursReply,
        ChatIdleAiReplyService $idleAiReply,
        ActiveTopicDetector $topicDetector,
    ): void
    {
        if (SystemSetting::getValue('module_funnels', 'on') !== 'on') {
            return;
        }

        $chat = Chat::query()->whereKey($this->chatId)->first();
        if ($chat === null || $chat->is_group || ! $chat->funnel_tracking_enabled || $chat->funnel_stage_locked) {
            return;
        }

        $latestInbound = Message::query()
            ->where('chat_id', $chat->id)
            ->where('direction', 'inbound')
            ->orderByDesc('message_timestamp')
            ->orderByDesc('id')
            ->first();

        if ($latestInbound === null || $latestInbound->id !== $this->triggerMessageId) {
            return;
        }

        // Keep the active topic up to date before routing/classification.
        $topicDetector->updateFromMessage($chat, $latestInbound);
        $chat->refresh();

        $department = $departmentRouting->resolveAndAssignDepartment($chat, $latestInbound);
        $chat->refresh();

        if ($offHoursReply->tryReply($chat, $latestInbound, $department)) {
            return;
        }

        if (AiOrchestratorRun::query()
            ->where('chat_id', $chat->id)
            ->where('trigger_message_id', $this->triggerMessageId)
            ->whereIn('status', [
                AiOrchestratorRun::STATUS_RUNNING,
                AiOrchestratorRun::STATUS_COMPLETED,
                AiOrchestratorRun::STATUS_NEEDS_MANAGER,
            ])
            ->exists()) {
            return;
        }

        $classification = $classifier->classify($chat, $latestInbound);
        if ($classification === null) {
            $this->dispatchFallbackReply($chat, $idleAiReply);

            return;
        }

        try {
            $state->applyFromAi($chat, $classification, $this->triggerMessageId);
            if (FunnelAiScenario::query()
                ->where('funnel_id', $classification->funnelId)
                ->where('company_id', $chat->company_id)
                ->where('enabled', true)
                ->exists()) {
                RunAiFunnelOrchestratorJob::dispatch($chat->id, $this->triggerMessageId, $chat->company_id)
                    ->delay(now()->addSeconds(max(3, (int) config('funnel.orchestrator.debounce_seconds', 20))));

                return;
            }

            $this->dispatchFallbackReply($chat, $idleAiReply);
        } catch (\Throwable $e) {
            Log::warning('[funnel-ai] apply failed', [
                'chat_id' => $chat->id,
                'error' => $e->getMessage(),
            ]);

            $this->dispatchFallbackReply($chat, $idleAiReply);
        }
    }

    private function dispatchFallbackReply(Chat $chat, ChatIdleAiReplyService $idleAiReply): void
    {
        if ($chat->ai_enabled) {
            $idleAiReply->dispatchGenerateReply($chat, $this->triggerMessageId);
        }
    }

    public function backoff(): array
    {
        return [10, 30];
    }
}
