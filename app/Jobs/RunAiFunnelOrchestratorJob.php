<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Chat;
use App\Models\Message;
use App\Services\AI\AiFunnelOrchestratorService;
use App\Services\AI\ChatOffHoursReplyService;
use App\Services\AI\WhatsappAiTypingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class RunAiFunnelOrchestratorJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct(
        public readonly int $chatId,
        public readonly int $triggerMessageId,
    ) {}

    public function handle(
        AiFunnelOrchestratorService $orchestrator,
        WhatsappAiTypingService $typing,
        ChatOffHoursReplyService $offHoursReply,
    ): void {
        $chat = Chat::query()->with(['departments', 'funnel.aiScenario'])->whereKey($this->chatId)->first();
        $trigger = Message::query()->whereKey($this->triggerMessageId)->first();

        if ($chat !== null && $trigger !== null && $offHoursReply->tryReply($chat, $trigger)) {
            return;
        }

        if ($chat === null) {
            $orchestrator->run($this->chatId, $this->triggerMessageId);

            return;
        }

        $typing->whileGenerating($chat, fn (): null => $orchestrator->run($this->chatId, $this->triggerMessageId));
    }

    public function viaQueue(): string
    {
        return 'ai';
    }
}
