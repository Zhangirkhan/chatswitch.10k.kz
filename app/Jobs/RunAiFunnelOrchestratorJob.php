<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\AiOrchestratorRun;
use App\Models\Chat;
use App\Models\Message;
use App\Services\AI\AiFunnelOrchestratorService;
use App\Services\AI\ChatDepartmentRoutingService;
use App\Services\AI\ChatOffHoursReplyService;
use App\Services\AI\WhatsappAiTypingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

final class RunAiFunnelOrchestratorJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly int $chatId,
        public readonly int $triggerMessageId,
        public readonly ?int $tenantCompanyId = null,
    ) {}

    /** Exponential backoff: 30 s, 90 s, 270 s. */
    public function backoff(): array
    {
        return [30, 90, 270];
    }

    /**
     * Handle permanent job failure: mark the orchestrator run as failed
     * and log so it surfaces in monitoring dashboards.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('[ai-orchestrator] job permanently failed', [
            'chat_id' => $this->chatId,
            'trigger_message_id' => $this->triggerMessageId,
            'error' => $exception->getMessage(),
        ]);

        // Best-effort: mark any RUNNING/PENDING run as failed so the chat is
        // not left in a permanently-stuck state.
        AiOrchestratorRun::query()
            ->where('chat_id', $this->chatId)
            ->where('trigger_message_id', $this->triggerMessageId)
            ->whereIn('status', [
                AiOrchestratorRun::STATUS_RUNNING,
                AiOrchestratorRun::STATUS_PENDING,
            ])
            ->update([
                'status' => AiOrchestratorRun::STATUS_FAILED,
                'error' => mb_substr('Job permanently failed: '.$exception->getMessage(), 0, 2000),
                'completed_at' => now(),
            ]);
    }

    public function handle(
        AiFunnelOrchestratorService $orchestrator,
        WhatsappAiTypingService $typing,
        ChatDepartmentRoutingService $departmentRouting,
        ChatOffHoursReplyService $offHoursReply,
    ): void {
        $chat = Chat::query()->with(['departments', 'funnel.aiScenario'])->whereKey($this->chatId)->first();
        $trigger = Message::query()->whereKey($this->triggerMessageId)->first();

        if ($chat === null || $trigger === null) {
            Log::warning('[ai-orchestrator] chat or trigger not found', [
                'chat_id' => $this->chatId,
                'trigger_message_id' => $this->triggerMessageId,
                'tenant_company_id' => $this->tenantCompanyId,
            ]);

            return;
        }

        $department = $departmentRouting->resolveAndAssignDepartment($chat, $trigger);
        $chat->refresh();
        if ($offHoursReply->tryReply($chat, $trigger, $department)) {
            return;
        }

        $typing->whileGenerating($chat, fn (): null => $orchestrator->run($this->chatId, $this->triggerMessageId));
    }

    public function viaQueue(): string
    {
        return 'default';
    }
}
