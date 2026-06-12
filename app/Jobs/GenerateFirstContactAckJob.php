<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\AiResponseLog;
use App\Models\Chat;
use App\Models\Message;
use App\Services\AI\ChatFirstContactAckService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

final class GenerateFirstContactAckJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly int $chatId,
        public readonly int $triggerMessageId,
        public readonly ?int $tenantCompanyId = null,
    ) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [15, 45, 120];
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('[first-contact-ack] job permanently failed', [
            'chat_id' => $this->chatId,
            'trigger_message_id' => $this->triggerMessageId,
            'error' => $exception->getMessage(),
        ]);

        AiResponseLog::query()
            ->where('trigger_message_id', $this->triggerMessageId)
            ->where('mode', ChatFirstContactAckService::LOG_MODE)
            ->whereIn('status', ['pending', 'generating'])
            ->update([
                'status' => 'failed',
                'error' => mb_substr('Job permanently failed: '.$exception->getMessage(), 0, 2000),
            ]);
    }

    public function handle(ChatFirstContactAckService $firstContactAck): void
    {
        $chat = Chat::query()
            ->with(['company:id,name', 'funnel.aiScenario', 'aiResponder', 'assignments.user', 'departments'])
            ->whereKey($this->chatId)
            ->first();
        $trigger = Message::query()->whereKey($this->triggerMessageId)->first();

        if ($chat === null || $trigger === null) {
            return;
        }

        $firstContactAck->generateAndSend($chat, $trigger);
    }

    /** @return list<WithoutOverlapping> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('first-contact-ack:'.$this->chatId))->releaseAfter(120)];
    }
}
