<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Jobs\GenerateAiReplyJob;
use App\Models\AiResponseLog;
use App\Models\Chat;
use App\Models\Message;
use App\Services\AI\Orchestrator\ClientMessageIntentDetector;
use App\Services\Memory\ContactAiContextResetService;
use App\Support\MessageInboundText;
use Illuminate\Support\Carbon;

/**
 * Планирование и проверки автоответа AI на входящее сообщение клиента.
 * Ответ уходит сразу после входящего (без задержки «простоя»).
 * Дожим, когда клиент не отвечает на сообщение AI, — через follow-up правил этапа воронки.
 */
final class ChatIdleAiReplyService
{
    public function __construct(
        private readonly ClientMessageIntentDetector $clientIntents,
        private readonly ContactAiContextResetService $contactAiContextReset,
    ) {}

    public function shouldSkipScheduling(Message $trigger): bool
    {
        if ($trigger->direction !== 'inbound') {
            return true;
        }

        $body = MessageInboundText::forMessage($trigger);

        return $this->clientIntents->isAcknowledgement($body);
    }

    public function dispatchGenerateReply(Chat $chat, int $triggerMessageId, bool $immediate = false): void
    {
        if (! $chat->ai_enabled || $chat->is_group) {
            return;
        }

        $trigger = Message::query()
            ->where('chat_id', $chat->id)
            ->whereKey($triggerMessageId)
            ->first();

        if ($trigger === null || $this->shouldSkipScheduling($trigger)) {
            return;
        }

        GenerateAiReplyJob::dispatch($chat->id, $triggerMessageId, $chat->company_id);
    }

    public function canExecuteReply(Chat $chat, Message $trigger): bool
    {
        if (! $chat->ai_enabled || $chat->is_group || $trigger->direction !== 'inbound') {
            return false;
        }

        if ($this->shouldSkipScheduling($trigger)) {
            return false;
        }

        if ((int) $trigger->chat_id !== (int) $chat->id) {
            return false;
        }

        if ($chat->contact_id !== null && $this->contactAiContextReset->isMessageBeforeReset((int) $chat->contact_id, $trigger)) {
            return false;
        }

        $latestInbound = Message::query()
            ->where('chat_id', $chat->id)
            ->where('direction', 'inbound')
            ->orderByDesc('message_timestamp')
            ->orderByDesc('id')
            ->first();

        if ($latestInbound === null || (int) $latestInbound->id !== (int) $trigger->id) {
            return false;
        }

        if ($this->alreadyAnsweredTrigger($chat, $trigger)) {
            return false;
        }

        return $this->clientStillWaiting($chat, $trigger);
    }

    private function clientStillWaiting(Chat $chat, Message $trigger): bool
    {
        $triggerAt = $this->messageAt($trigger);
        if ($triggerAt === null) {
            return false;
        }

        $hasHumanReplyAfter = Message::query()
            ->where('chat_id', $chat->id)
            ->where('direction', 'outbound')
            ->where('message_timestamp', '>', $triggerAt)
            ->whereNotNull('sent_by_user_id')
            ->where(function ($query): void {
                $query
                    ->whereNull('metadata->ai->generated')
                    ->orWhere('metadata->ai->generated', false);
            })
            ->exists();

        return ! $hasHumanReplyAfter;
    }

    private function alreadyAnsweredTrigger(Chat $chat, Message $trigger): bool
    {
        $mode = $chat->ai_mode === 'draft' ? 'draft' : 'auto';

        return AiResponseLog::query()
            ->where('trigger_message_id', $trigger->id)
            ->where('mode', $mode)
            ->whereIn('status', ['sent', 'drafted', 'generating'])
            ->exists();
    }

    private function messageAt(Message $message): ?Carbon
    {
        $at = $message->message_timestamp;

        return $at instanceof Carbon ? $at : ($at !== null ? Carbon::parse((string) $at) : null);
    }
}
