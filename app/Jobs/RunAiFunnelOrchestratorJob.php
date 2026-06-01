<?php

declare(strict_types=1);

namespace App\Jobs;

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

    public int $tries = 2;

    public function __construct(
        public readonly int $chatId,
        public readonly int $triggerMessageId,
        public readonly ?int $tenantCompanyId = null,
    ) {}

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
