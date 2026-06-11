<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\Chat;
use App\Models\ChatNurtureSequence;
use App\Models\Message;
use App\Models\ScheduledMessage;
use App\Models\User;
use App\Support\AiFeatureFlags;
use Illuminate\Support\Facades\Log;

/**
 * D+2 / D+7 / D+14 nurture cadence when the client defers («подумаем», «позже»).
 */
final class ChatNurtureSequenceService
{
    /** @var array<int, int> day offset => step number */
    private const STEPS = [
        2 => 1,
        7 => 2,
        14 => 3,
    ];

    public function startFromDeferral(Chat $chat, Message $trigger): ?ChatNurtureSequence
    {
        if (! AiFeatureFlags::enabled(AiFeatureFlags::NURTURE_FOLLOW_UP, $chat->company_id)) {
            return null;
        }

        if ($chat->is_lead_closed || ! $chat->ai_enabled) {
            return null;
        }

        $active = ChatNurtureSequence::query()
            ->where('chat_id', $chat->id)
            ->where('status', ChatNurtureSequence::STATUS_ACTIVE)
            ->exists();

        if ($active) {
            return null;
        }

        $session = $chat->whatsappSession;
        $sender = $this->resolveSender($chat);
        if ($session === null || $sender === null) {
            return null;
        }

        $sequence = ChatNurtureSequence::query()->create([
            'company_id' => (int) $chat->company_id,
            'chat_id' => (int) $chat->id,
            'trigger_message_id' => $trigger->id,
            'status' => ChatNurtureSequence::STATUS_ACTIVE,
            'current_step' => 0,
            'started_at' => now(),
        ]);

        foreach (self::STEPS as $days => $step) {
            $body = $this->messageForStep($chat, $step);
            ScheduledMessage::query()->create([
                'chat_id' => $chat->id,
                'whatsapp_session_id' => $session->id,
                'user_id' => $sender->id,
                'purpose' => ScheduledMessage::PURPOSE_NURTURE_FOLLOW_UP,
                'body' => $body,
                'display_body' => $body,
                'scheduled_at' => now()->addDays($days),
                'status' => ScheduledMessage::STATUS_PENDING,
                'metadata' => [
                    'sequence_id' => $sequence->id,
                    'step' => $step,
                    'day_offset' => $days,
                ],
            ]);
        }

        Log::info('[nurture] sequence started', [
            'chat_id' => $chat->id,
            'sequence_id' => $sequence->id,
        ]);

        return $sequence;
    }

    public function cancelForChat(Chat $chat, string $reason): int
    {
        $cancelled = ScheduledMessage::query()
            ->where('chat_id', $chat->id)
            ->where('purpose', ScheduledMessage::PURPOSE_NURTURE_FOLLOW_UP)
            ->where('status', ScheduledMessage::STATUS_PENDING)
            ->update([
                'status' => ScheduledMessage::STATUS_CANCELLED,
                'error' => null,
            ]);

        ChatNurtureSequence::query()
            ->where('chat_id', $chat->id)
            ->where('status', ChatNurtureSequence::STATUS_ACTIVE)
            ->update([
                'status' => ChatNurtureSequence::STATUS_CANCELLED,
                'cancel_reason' => $reason,
                'cancelled_at' => now(),
            ]);

        if ($cancelled > 0) {
            Log::info('[nurture] cancelled', ['chat_id' => $chat->id, 'reason' => $reason, 'messages' => $cancelled]);
        }

        return $cancelled;
    }

    public function onStepSent(ScheduledMessage $scheduled): void
    {
        if ($scheduled->purpose !== ScheduledMessage::PURPOSE_NURTURE_FOLLOW_UP) {
            return;
        }

        $metadata = is_array($scheduled->metadata) ? $scheduled->metadata : [];
        $sequenceId = $metadata['sequence_id'] ?? null;
        $step = $metadata['step'] ?? null;

        if ($sequenceId === null || $step === null) {
            return;
        }

        $sequence = ChatNurtureSequence::query()->find($sequenceId);
        if ($sequence === null || $sequence->status !== ChatNurtureSequence::STATUS_ACTIVE) {
            return;
        }

        $sequence->forceFill(['current_step' => max((int) $sequence->current_step, (int) $step)])->save();

        if ((int) $step >= 3) {
            $sequence->forceFill([
                'status' => ChatNurtureSequence::STATUS_COMPLETED,
                'completed_at' => now(),
            ])->save();
        }
    }

    private function messageForStep(Chat $chat, int $step): string
    {
        $name = trim((string) ($chat->chat_name ?? ''));
        if ($name === '') {
            $name = 'клиент';
        }

        return match ($step) {
            1 => "Добрый день, {$name}! Как продвигается решение? Если нужно — подскажу по вариантам.",
            2 => "Здравствуйте, {$name}! Напомню: мы можем подобрать решение под ваш запрос. Актуально ли ещё?",
            default => "Добрый день, {$name}! Если вопрос ещё актуален — напишите, поможем. Если нет — просто дайте знать.",
        };
    }

    private function resolveSender(Chat $chat): ?User
    {
        if ($chat->ai_responder_user_id) {
            $user = User::query()->whereKey($chat->ai_responder_user_id)->where('is_active', true)->first();
            if ($user instanceof User) {
                return $user;
            }
        }

        $assignmentUserId = $chat->assignments()->value('user_id');
        if ($assignmentUserId) {
            $user = User::query()->whereKey($assignmentUserId)->where('is_active', true)->first();
            if ($user instanceof User) {
                return $user;
            }
        }

        return User::query()
            ->where('company_id', $chat->company_id)
            ->where('is_active', true)
            ->orderBy('id')
            ->first();
    }
}
