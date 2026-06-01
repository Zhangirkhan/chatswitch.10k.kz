<?php

declare(strict_types=1);

namespace App\Services\Broadcast;

use App\Models\BroadcastCampaignItem;
use App\Models\Message;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Лимит исходящих с одного WhatsApp-номера: не более 100 сообщений в скользящие сутки.
 * Между сообщениями рассылки — случайная пауза в заданном диапазоне (~6–22 мин).
 */
final class BroadcastSendRateLimiter
{
    public const MAX_MESSAGES_PER_DAY = 100;

    public const SECONDS_PER_DAY = 86400;

    /** @deprecated Используйте MAX_MESSAGES_PER_DAY */
    public const MAX_MESSAGES_PER_HOUR = self::MAX_MESSAGES_PER_DAY;

    public function minDelayBetweenMessages(): int
    {
        $average = (int) floor(self::SECONDS_PER_DAY / self::MAX_MESSAGES_PER_DAY);

        return max(60, (int) floor($average * 0.5));
    }

    public function maxDelayBetweenMessages(): int
    {
        $average = (int) floor(self::SECONDS_PER_DAY / self::MAX_MESSAGES_PER_DAY);

        return max($this->minDelayBetweenMessages() + 1, (int) ceil($average * 1.5));
    }

    public function randomDelayBetweenMessages(): int
    {
        return random_int($this->minDelayBetweenMessages(), $this->maxDelayBetweenMessages());
    }

    /** Средняя пауза (для отображения в UI и поля delay_seconds кампании). */
    public function delayBetweenMessages(): int
    {
        return (int) round(($this->minDelayBetweenMessages() + $this->maxDelayBetweenMessages()) / 2);
    }

    public function outboundCountLastDay(int $whatsappSessionId): int
    {
        return Message::query()
            ->where('whatsapp_session_id', $whatsappSessionId)
            ->where('direction', 'outbound')
            ->where('created_at', '>=', now()->subDay())
            ->count();
    }

    public function pendingBroadcastCountInWindow(int $whatsappSessionId): int
    {
        return BroadcastCampaignItem::query()
            ->where('status', BroadcastCampaignItem::STATUS_PENDING)
            ->where('scheduled_at', '>=', now()->subDay())
            ->whereHas('campaign', fn ($q) => $q->where('whatsapp_session_id', $whatsappSessionId))
            ->count();
    }

    public function reservedCountLastDay(int $whatsappSessionId): int
    {
        return $this->outboundCountLastDay($whatsappSessionId)
            + $this->pendingBroadcastCountInWindow($whatsappSessionId);
    }

    public function remainingQuota(int $whatsappSessionId): int
    {
        return max(0, self::MAX_MESSAGES_PER_DAY - $this->reservedCountLastDay($whatsappSessionId));
    }

    public function assertCanSchedule(int $whatsappSessionId, int $plannedSends): void
    {
        if ($plannedSends <= 0) {
            return;
        }

        $remaining = $this->remainingQuota($whatsappSessionId);
        if ($plannedSends > $remaining) {
            throw ValidationException::withMessages([
                'file' => [
                    'Лимит WhatsApp: не более '.self::MAX_MESSAGES_PER_DAY.' исходящих в сутки с одного номера. '
                    ."Сейчас можно отправить ещё {$remaining}, в рассылке — {$plannedSends}.",
                ],
            ]);
        }
    }

    public function nextScheduleSlot(int $whatsappSessionId): Carbon
    {
        $cursor = now();

        $lastOutbound = Message::query()
            ->where('whatsapp_session_id', $whatsappSessionId)
            ->where('direction', 'outbound')
            ->orderByDesc('id')
            ->value('created_at');

        if ($lastOutbound !== null) {
            $afterOutbound = Carbon::parse($lastOutbound)->addSeconds($this->randomDelayBetweenMessages());
            if ($afterOutbound->greaterThan($cursor)) {
                $cursor = $afterOutbound;
            }
        }

        $lastScheduled = BroadcastCampaignItem::query()
            ->where('status', BroadcastCampaignItem::STATUS_PENDING)
            ->whereHas('campaign', fn ($q) => $q->where('whatsapp_session_id', $whatsappSessionId))
            ->orderByDesc('scheduled_at')
            ->value('scheduled_at');

        if ($lastScheduled !== null) {
            $afterScheduled = Carbon::parse($lastScheduled)->addSeconds($this->randomDelayBetweenMessages());
            if ($afterScheduled->greaterThan($cursor)) {
                $cursor = $afterScheduled;
            }
        }

        return $cursor;
    }

    public function canSendNow(int $whatsappSessionId): bool
    {
        return $this->outboundCountLastDay($whatsappSessionId) < self::MAX_MESSAGES_PER_DAY;
    }

    /**
     * @return array{
     *     max_per_day: int,
     *     delay_seconds: int,
     *     delay_seconds_min: int,
     *     delay_seconds_max: int,
     *     sent_last_day: int,
     *     remaining: int,
     *     max_per_hour: int,
     *     sent_last_hour: int,
     * }
     */
    public function snapshot(int $whatsappSessionId): array
    {
        $sent = $this->outboundCountLastDay($whatsappSessionId);
        $remaining = max(0, self::MAX_MESSAGES_PER_DAY - $this->reservedCountLastDay($whatsappSessionId));

        return [
            'max_per_day' => self::MAX_MESSAGES_PER_DAY,
            'delay_seconds' => $this->delayBetweenMessages(),
            'delay_seconds_min' => $this->minDelayBetweenMessages(),
            'delay_seconds_max' => $this->maxDelayBetweenMessages(),
            'sent_last_day' => $sent,
            'remaining' => $remaining,
            // Обратная совместимость для старого фронта
            'max_per_hour' => self::MAX_MESSAGES_PER_DAY,
            'sent_last_hour' => $sent,
        ];
    }
}
