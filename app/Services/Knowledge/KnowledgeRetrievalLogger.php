<?php

declare(strict_types=1);

namespace App\Services\Knowledge;

use App\Models\KnowledgeRetrievalLog;
use Illuminate\Support\Facades\Schema;

final class KnowledgeRetrievalLogger
{
    /**
     * @param  list<array{chunk_id: int, similarity: float, domain: string|null}>  $hits
     */
    public function log(int $companyId, array $hits, ?int $aiResponseLogId = null): void
    {
        if (! Schema::hasTable('knowledge_retrieval_logs') || $hits === []) {
            return;
        }

        foreach ($hits as $hit) {
            KnowledgeRetrievalLog::query()->create([
                'company_id' => $companyId,
                'ai_response_log_id' => $aiResponseLogId,
                'chunk_id' => (int) $hit['chunk_id'],
                'similarity' => $hit['similarity'],
                'domain' => $hit['domain'],
            ]);
        }
    }

    /**
     * @param  list<array{chunk_id: int, similarity: float, domain: string|null}>  $hits
     * @return list<array{id: int, similarity: float, domain: string|null}>
     */
    public function manifestHits(array $hits): array
    {
        return array_map(static fn (array $hit): array => [
            'id' => (int) $hit['chunk_id'],
            'similarity' => round((float) $hit['similarity'], 4),
            'domain' => $hit['domain'],
        ], $hits);
    }
}
