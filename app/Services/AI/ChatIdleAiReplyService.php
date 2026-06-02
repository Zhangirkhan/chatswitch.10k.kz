<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Jobs\GenerateAiReplyJob;
use App\Models\AiResponseLog;
use App\Models\Chat;
use App\Models\Message;
use App\Services\AI\Orchestrator\ClientMessageIntentDetector;
use App\Support\MessageInboundText;
use Illuminate\Support\Carbon;

/**
 * Автоответ AI только после простоя в диалоге (по умолчанию 10 минут).
 * Не планирует ответ на «спасибо» и короткие подтверждения.
 * Если менеджер уже ответил клиенту — AI ждёт, пока снова не наступит простой
 * или оператор вручную включит AI (немедленный ответ на последнее входящее).
 */
final class ChatIdleAiReplyService
{
    public function __construct(
        private readonly ClientMessageIntentDetector $clientIntents,
    ) {}

    public function idleMinutes(): int
    {
        return max(0, (int) config('accel.ai_idle_reply_minutes', 10));
    }

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

        $pending = GenerateAiReplyJob::dispatch($chat->id, $triggerMessageId, $chat->company_id);

        $minutes = $this->idleMinutes();
        if (! $immediate && $minutes > 0) {
            $pending->delay(now()->addMinutes($minutes));
        }
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

        $minutes = $this->idleMinutes();
        if ($minutes === 0) {
            return $this->clientStillWaiting($chat, $trigger);
        }

        $lastMessage = $this->lastConversationMessage($chat);
        if ($lastMessage === null) {
            return false;
        }

        $idleThreshold = now()->subMinutes($minutes);
        $lastAt = $this->messageAt($lastMessage);

        if ($lastAt === null || $lastAt->greaterThan($idleThreshold)) {
            return false;
        }

        if ($lastMessage->direction !== 'inbound') {
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

    private function lastConversationMessage(Chat $chat): ?Message
    {
        return Message::query()
            ->where('chat_id', $chat->id)
            ->whereIn('direction', ['inbound', 'outbound'])
            ->orderByDesc('message_timestamp')
            ->orderByDesc('id')
            ->first();
    }

    private function messageAt(Message $message): ?Carbon
    {
        $at = $message->message_timestamp;

        return $at instanceof Carbon ? $at : ($at !== null ? Carbon::parse((string) $at) : null);
    }
}
