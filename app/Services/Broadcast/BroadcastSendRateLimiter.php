<?php

declare(strict_types=1);

namespace App\Services\Broadcast;

use App\Models\BroadcastCampaignItem;
use App\Models\Message;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Лимит исходящих с одного WhatsApp-номера: не более 100 сообщений в скользящий час.
 */
final class BroadcastSendRateLimiter
{
    public const MAX_MESSAGES_PER_HOUR = 100;

    public const SECONDS_PER_HOUR = 3600;

    public function delayBetweenMessages(): int
    {
        return (int) ceil(self::SECONDS_PER_HOUR / self::MAX_MESSAGES_PER_HOUR);
    }

    public function outboundCountLastHour(int $whatsappSessionId): int
    {
        return Message::query()
            ->where('whatsapp_session_id', $whatsappSessionId)
            ->where('direction', 'outbound')
            ->where('created_at', '>=', now()->subHour())
            ->count();
    }

    public function pendingBroadcastCountInWindow(int $whatsappSessionId): int
    {
        return BroadcastCampaignItem::query()
            ->where('status', BroadcastCampaignItem::STATUS_PENDING)
            ->where('scheduled_at', '>=', now()->subHour())
            ->whereHas('campaign', fn ($q) => $q->where('whatsapp_session_id', $whatsappSessionId))
            ->count();
    }

    public function reservedCountLastHour(int $whatsappSessionId): int
    {
        return $this->outboundCountLastHour($whatsappSessionId)
            + $this->pendingBroadcastCountInWindow($whatsappSessionId);
    }

    public function remainingQuota(int $whatsappSessionId): int
    {
        return max(0, self::MAX_MESSAGES_PER_HOUR - $this->reservedCountLastHour($whatsappSessionId));
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
                    'Лимит WhatsApp: не более '.self::MAX_MESSAGES_PER_HOUR.' исходящих в час с одного номера. '
                    ."Сейчас можно отправить ещё {$remaining}, в рассылке — {$plannedSends}.",
                ],
            ]);
        }
    }

    public function nextScheduleSlot(int $whatsappSessionId): Carbon
    {
        $delay = $this->delayBetweenMessages();
        $cursor = now();

        $lastOutbound = Message::query()
            ->where('whatsapp_session_id', $whatsappSessionId)
            ->where('direction', 'outbound')
            ->orderByDesc('id')
            ->value('created_at');

        if ($lastOutbound !== null) {
            $afterOutbound = Carbon::parse($lastOutbound)->addSeconds($delay);
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
            $afterScheduled = Carbon::parse($lastScheduled)->addSeconds($delay);
            if ($afterScheduled->greaterThan($cursor)) {
                $cursor = $afterScheduled;
            }
        }

        return $cursor;
    }

    public function canSendNow(int $whatsappSessionId): bool
    {
        return $this->outboundCountLastHour($whatsappSessionId) < self::MAX_MESSAGES_PER_HOUR;
    }

    /** @return array{max_per_hour: int, delay_seconds: int, sent_last_hour: int, remaining: int} */
    public function snapshot(int $whatsappSessionId): array
    {
        $sent = $this->outboundCountLastHour($whatsappSessionId);

        return [
            'max_per_hour' => self::MAX_MESSAGES_PER_HOUR,
            'delay_seconds' => $this->delayBetweenMessages(),
            'sent_last_hour' => $sent,
            'remaining' => max(0, self::MAX_MESSAGES_PER_HOUR - $this->reservedCountLastHour($whatsappSessionId)),
        ];
    }
}
