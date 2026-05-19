<?php

declare(strict_types=1);

namespace App\Services\Funnel;

use App\Models\Chat;
use App\Models\ChatFunnelTransition;
use App\Models\Message;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Среднее время ответа на inbound-сообщение клиента в рамках этапа воронки (AI vs менеджер).
 */
final class FunnelStageResponseTimeAnalytics
{
    private const MAX_REPLY_SECONDS = 86400;

    /**
     * @param  list<int>  $chatIds
     * @param  list<int>  $stageIds
     * @return array<int, array{
     *     avg_response_minutes_ai: float|null,
     *     avg_response_minutes_manager: float|null,
     *     response_samples_ai: int,
     *     response_samples_manager: int
     * }>
     */
    public function avgMinutesByStage(array $chatIds, array $stageIds, Carbon $from, Carbon $to): array
    {
        if ($chatIds === [] || $stageIds === []) {
            return [];
        }

        $stageIdSet = array_fill_keys($stageIds, true);
        $buckets = [];
        foreach ($stageIds as $stageId) {
            $buckets[$stageId] = ['ai' => [], 'manager' => []];
        }

        $transitionsByChat = ChatFunnelTransition::query()
            ->whereIn('chat_id', $chatIds)
            ->whereNotNull('to_stage_id')
            ->orderBy('chat_id')
            ->orderBy('created_at')
            ->get(['chat_id', 'to_stage_id', 'created_at'])
            ->groupBy(fn (ChatFunnelTransition $transition): int => (int) $transition->chat_id);

        $messages = Message::query()
            ->whereIn('chat_id', $chatIds)
            ->whereIn('direction', ['inbound', 'outbound'])
            ->where(function ($q) use ($from, $to): void {
                $q->whereBetween('message_timestamp', [$from, $to])
                    ->orWhere(function ($inner) use ($from, $to): void {
                        $inner->whereNull('message_timestamp')
                            ->whereBetween('created_at', [$from, $to]);
                    });
            })
            ->orderBy('chat_id')
            ->orderByRaw('COALESCE(message_timestamp, created_at)')
            ->orderBy('id')
            ->get(['id', 'chat_id', 'direction', 'message_timestamp', 'created_at', 'metadata']);

        $messagesByChat = $messages->groupBy(fn (Message $message): int => (int) $message->chat_id);

        $chatStageIds = Chat::query()
            ->whereIn('id', $chatIds)
            ->pluck('funnel_stage_id', 'id')
            ->mapWithKeys(fn ($stageId, $chatId) => [(int) $chatId => (int) $stageId])
            ->all();

        foreach ($messagesByChat as $chatId => $chatMessages) {
            $chatId = (int) $chatId;
            $chatTransitions = $transitionsByChat->get($chatId, collect());

            /** @var Carbon|null $pendingInboundAt */
            $pendingInboundAt = null;
            /** @var int|null $pendingStageId */
            $pendingStageId = null;

            foreach ($chatMessages as $message) {
                $at = $this->messageAt($message);
                if ($at === null || $at->lt($from) || $at->gt($to)) {
                    continue;
                }

                if ($message->direction === 'inbound') {
                    $stageId = $this->stageAtTime($chatTransitions, $at);
                    if ($stageId === null && $chatTransitions->isEmpty()) {
                        $stageId = (int) ($chatStageIds[$chatId] ?? 0);
                    }
                    if ($stageId === null || $stageId <= 0 || ! isset($stageIdSet[$stageId])) {
                        $pendingInboundAt = null;
                        $pendingStageId = null;

                        continue;
                    }
                    $pendingInboundAt = $at;
                    $pendingStageId = $stageId;

                    continue;
                }

                if ($message->direction !== 'outbound' || $pendingInboundAt === null || $pendingStageId === null) {
                    continue;
                }

                $stageId = $pendingStageId;
                $seconds = (int) $pendingInboundAt->diffInSeconds($at);
                $pendingInboundAt = null;
                $pendingStageId = null;

                if ($seconds <= 0 || $seconds > self::MAX_REPLY_SECONDS) {
                    continue;
                }

                $bucket = $this->isAiOutbound($message) ? 'ai' : 'manager';
                $buckets[$stageId][$bucket][] = $seconds / 60;
            }
        }

        $result = [];
        foreach ($buckets as $stageId => $data) {
            $result[$stageId] = [
                'avg_response_minutes_ai' => $this->average($data['ai']),
                'avg_response_minutes_manager' => $this->average($data['manager']),
                'response_samples_ai' => count($data['ai']),
                'response_samples_manager' => count($data['manager']),
            ];
        }

        return $result;
    }

    /**
     * @param  Collection<int, ChatFunnelTransition>  $transitions
     */
    private function stageAtTime(Collection $transitions, Carbon $at): ?int
    {
        if ($transitions->isEmpty()) {
            return null;
        }

        $stageId = null;
        foreach ($transitions->sortBy('created_at') as $transition) {
            if ($transition->to_stage_id === null) {
                continue;
            }
            $enteredAt = Carbon::parse($transition->created_at);
            if ($enteredAt->lte($at)) {
                $stageId = (int) $transition->to_stage_id;
            }
        }

        return $stageId;
    }

    private function messageAt(Message $message): ?Carbon
    {
        $raw = $message->message_timestamp ?? $message->created_at;

        return $raw !== null ? Carbon::parse($raw) : null;
    }

    private function isAiOutbound(Message $message): bool
    {
        $metadata = $message->metadata;

        if (! is_array($metadata)) {
            return false;
        }

        return data_get($metadata, 'ai.generated') === true;
    }

    /**
     * @param  list<float>  $values
     */
    private function average(array $values): ?float
    {
        if ($values === []) {
            return null;
        }

        return round(array_sum($values) / count($values), 1);
    }
}
