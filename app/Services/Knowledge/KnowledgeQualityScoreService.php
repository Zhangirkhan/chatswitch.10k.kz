<?php

declare(strict_types=1);

namespace App\Services\Knowledge;

use App\Models\DealOutcome;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeChunkStat;
use App\Models\KnowledgeRetrievalLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class KnowledgeQualityScoreService
{
    private const OUTCOME_WINDOW_DAYS = 14;

    public function computeForCompany(int $companyId): int
    {
        if (! Schema::hasTable('knowledge_chunk_stats')) {
            return 0;
        }

        $chunks = KnowledgeChunk::query()
            ->where('company_id', $companyId)
            ->get(['id', 'company_id', 'display_line', 'embedding']);

        if ($chunks->isEmpty()) {
            return 0;
        }

        $retrievalStats = $this->retrievalAggregates($companyId);
        $outcomeStats = $this->outcomeAggregates($companyId);
        $now = now();
        $updated = 0;

        foreach ($chunks as $chunk) {
            $chunkId = (int) $chunk->id;
            $retrieval = $retrievalStats[$chunkId] ?? ['count' => 0, 'last_at' => null];
            $outcomes = $outcomeStats[$chunkId] ?? ['won' => 0, 'lost' => 0];

            $retrievalCount = (int) $retrieval['count'];
            $usageNorm = min(1.0, $retrievalCount / max(1, (int) config('knowledge.quality.retrieval_norm_cap', 50)));
            $totalOutcomes = $outcomes['won'] + $outcomes['lost'];
            $outcomeFactor = $totalOutcomes > 0
                ? 0.5 + ($outcomes['won'] / $totalOutcomes) * 0.5
                : 0.75;
            $freshness = $this->freshnessFactor($retrieval['last_at'], $now);
            $catalogHealth = $this->catalogHealthFactor($chunk);

            $score = round(min(100, max(0,
                ($usageNorm * 40)
                + ($outcomeFactor * 40)
                + ($freshness * 10)
                + ($catalogHealth * 10)
            )), 2);

            KnowledgeChunkStat::query()->updateOrCreate(
                ['chunk_id' => $chunkId],
                [
                    'company_id' => $companyId,
                    'retrieval_count' => $retrievalCount,
                    'reply_count' => $retrievalCount,
                    'won_after_use' => $outcomes['won'],
                    'lost_after_use' => $outcomes['lost'],
                    'last_retrieved_at' => $retrieval['last_at'],
                    'quality_score' => $score,
                    'computed_at' => $now,
                ],
            );

            $chunk->forceFill(['quality_score' => $score])->save();
            $updated++;
        }

        return $updated;
    }

    /**
     * @return array<int, array{count: int, last_at: Carbon|null}>
     */
    private function retrievalAggregates(int $companyId): array
    {
        if (! Schema::hasTable('knowledge_retrieval_logs')) {
            return [];
        }

        $rows = KnowledgeRetrievalLog::query()
            ->where('company_id', $companyId)
            ->selectRaw('chunk_id, COUNT(*) as cnt, MAX(created_at) as last_at')
            ->groupBy('chunk_id')
            ->get();

        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row->chunk_id] = [
                'count' => (int) $row->cnt,
                'last_at' => $row->last_at !== null ? Carbon::parse((string) $row->last_at) : null,
            ];
        }

        return $result;
    }

    /**
     * @return array<int, array{won: int, lost: int}>
     */
    private function outcomeAggregates(int $companyId): array
    {
        if (! Schema::hasTable('knowledge_retrieval_logs')
            || ! Schema::hasTable('ai_response_logs')
            || ! Schema::hasTable('deal_outcomes')
        ) {
            return [];
        }

        $logs = KnowledgeRetrievalLog::query()
            ->where('company_id', $companyId)
            ->whereNotNull('ai_response_log_id')
            ->get(['chunk_id', 'ai_response_log_id', 'created_at']);

        if ($logs->isEmpty()) {
            return [];
        }

        $logIds = $logs->pluck('ai_response_log_id')->unique()->filter()->all();
        $responseChatMap = DB::table('ai_response_logs')
            ->whereIn('id', $logIds)
            ->pluck('chat_id', 'id');

        $chatIds = collect($responseChatMap)->filter()->unique()->values()->all();
        $outcomes = DealOutcome::query()
            ->whereIn('chat_id', $chatIds)
            ->get(['chat_id', 'won', 'closed_at']);

        $result = [];
        foreach ($logs as $log) {
            $chatId = $responseChatMap[$log->ai_response_log_id] ?? null;
            if ($chatId === null || $log->created_at === null) {
                continue;
            }

            $windowEnd = Carbon::parse($log->created_at)->addDays(self::OUTCOME_WINDOW_DAYS);
            $matched = $outcomes->first(static function (DealOutcome $outcome) use ($chatId, $log, $windowEnd): bool {
                return (int) $outcome->chat_id === (int) $chatId
                    && $outcome->closed_at !== null
                    && $outcome->closed_at->gte($log->created_at)
                    && $outcome->closed_at->lte($windowEnd);
            });

            if ($matched === null) {
                continue;
            }

            $chunkId = (int) $log->chunk_id;
            $result[$chunkId] ??= ['won' => 0, 'lost' => 0];
            if ($matched->won) {
                $result[$chunkId]['won']++;
            } else {
                $result[$chunkId]['lost']++;
            }
        }

        return $result;
    }

    private function freshnessFactor(?Carbon $lastRetrieved, Carbon $now): float
    {
        if ($lastRetrieved === null) {
            return 0.3;
        }

        $days = max(0, $lastRetrieved->diffInDays($now));

        return max(0.2, 1.0 - min(1.0, $days / 90));
    }

    private function catalogHealthFactor(KnowledgeChunk $chunk): float
    {
        $score = 0.0;
        if (trim((string) $chunk->display_line) !== '') {
            $score += 0.5;
        }
        if (is_array($chunk->embedding) && $chunk->embedding !== []) {
            $score += 0.5;
        }

        return $score;
    }

    /**
     * @return array<int, float>
     */
    public function scoreMapForCompany(int $companyId): array
    {
        if (! Schema::hasTable('knowledge_chunk_stats')) {
            return [];
        }

        return KnowledgeChunkStat::query()
            ->where('company_id', $companyId)
            ->pluck('quality_score', 'chunk_id')
            ->mapWithKeys(static fn ($score, $chunkId): array => [(int) $chunkId => (float) $score])
            ->all();
    }
}
