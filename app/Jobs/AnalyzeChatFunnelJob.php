<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Chat;
use App\Models\Message;
use App\Models\SystemSetting;
use App\Services\AI\ChatFunnelClassifierService;
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
    ) {}

    public function handle(ChatFunnelClassifierService $classifier, ChatFunnelStateService $state): void
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

        $classification = $classifier->classify($chat, $latestInbound);
        if ($classification === null) {
            return;
        }

        try {
            $state->applyFromAi($chat, $classification, $this->triggerMessageId);
        } catch (\Throwable $e) {
            Log::warning('[funnel-ai] apply failed', [
                'chat_id' => $chat->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function backoff(): array
    {
        return [10, 30];
    }
}
