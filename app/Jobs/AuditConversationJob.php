<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Chat;
use App\Services\AI\ConversationAuditorService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class AuditConversationJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $uniqueFor = 300;

    public function __construct(
        public readonly int $chatId,
        public readonly ?int $triggerMessageId = null,
    ) {}

    public function uniqueId(): string
    {
        return 'audit-chat:'.$this->chatId;
    }

    public function handle(ConversationAuditorService $auditor): void
    {
        $chat = Chat::query()->find($this->chatId);
        if ($chat === null) {
            return;
        }

        $auditor->audit($chat, $this->triggerMessageId);
    }
}
