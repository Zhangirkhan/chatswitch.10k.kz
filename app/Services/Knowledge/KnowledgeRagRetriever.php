<?php

declare(strict_types=1);

namespace App\Services\Knowledge;

use App\Models\KnowledgeChunk;
use App\Services\AI\OpenAiEmbeddingService;
use App\Support\VectorCosine;
use Illuminate\Support\Facades\Log;
use Throwable;

final class KnowledgeRagRetriever
{
    public function __construct(
        private readonly OpenAiEmbeddingService $embeddings,
    ) {}

    public function shouldUseForQuery(?string $query): bool
    {
        if (! config('knowledge.rag.enabled', true)) {
            return false;
        }

        if ((string) config('services.openai.api_key') === '') {
            return false;
        }

        $trimmed = trim((string) $query);
        $minLength = (int) config('knowledge.rag.min_query_length', 3);

        return mb_strlen($trimmed) >= $minLength;
    }

    /**
     * @return list<string>
     */
    public function retrieveLines(int $companyId, string $query): array
    {
        $chunks = KnowledgeChunk::query()
            ->where('company_id', $companyId)
            ->whereNotNull('embedding')
            ->get();

        if ($chunks->isEmpty()) {
            return [];
        }

        try {
            $queryVector = $this->embeddings->embed($query, new \App\Services\AI\AiUsageOptions('rag_embed', $companyId));
        } catch (Throwable $e) {
            Log::warning('[knowledge-rag] query embedding failed', [
                'company_id' => $companyId,
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        if ($queryVector === []) {
            return [];
        }

        $minSimilarity = (float) config('knowledge.rag.min_similarity', 0.30);
        $topK = (int) config('knowledge.rag.top_k', 12);
        $maxRules = (int) config('knowledge.rag.max_rules', 5);

        $scored = [];
        foreach ($chunks as $chunk) {
            $vector = $chunk->embedding;
            if (! is_array($vector) || $vector === []) {
                continue;
            }

            $score = VectorCosine::similarity($queryVector, array_map(static fn ($v): float => (float) $v, $vector));
            if ($score < $minSimilarity) {
                continue;
            }

            $scored[] = [
                'chunk' => $chunk,
                'score' => $score,
            ];
        }

        if ($scored === []) {
            return [];
        }

        usort($scored, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        $selected = $this->selectTopChunks($scored, $topK, $maxRules);

        return $this->formatRetrievedLines($selected);
    }

    /**
     * @param  list<array{chunk: KnowledgeChunk, score: float}>  $scored
     * @return list<KnowledgeChunk>
     */
    private function selectTopChunks(array $scored, int $topK, int $maxRules): array
    {
        $picked = [];
        $ruleCount = 0;

        foreach ($scored as $row) {
            if (count($picked) >= $topK) {
                break;
            }

            /** @var KnowledgeChunk $chunk */
            $chunk = $row['chunk'];
            if ($chunk->source_type === KnowledgeChunk::TYPE_RULE) {
                if ($ruleCount >= $maxRules) {
                    continue;
                }
                $ruleCount++;
            }

            $picked[] = $chunk;
        }

        return $picked;
    }

    /**
     * @param  list<KnowledgeChunk>  $chunks
     * @return list<string>
     */
    private function formatRetrievedLines(array $chunks): array
    {
        $rules = [];
        $products = [];
        $services = [];

        foreach ($chunks as $chunk) {
            $line = trim($chunk->display_line);
            if ($line === '') {
                continue;
            }

            match ($chunk->source_type) {
                KnowledgeChunk::TYPE_RULE => $rules[] = $line,
                KnowledgeChunk::TYPE_PRODUCT => $products[] = $line,
                KnowledgeChunk::TYPE_SERVICE => $services[] = $line,
                default => null,
            };
        }

        $lines = ['Релевантные записи (подбор по запросу):'];

        if ($rules !== []) {
            $lines[] = 'Правила ответа:';
            array_push($lines, ...$rules);
        }

        if ($products !== []) {
            $lines[] = 'Товары:';
            array_push($lines, ...$products);
        }

        if ($services !== []) {
            $lines[] = 'Услуги:';
            array_push($lines, ...$services);
        }

        return count($lines) > 1 ? $lines : [];
    }

    /**
     * @return array{enabled: bool, indexed: int, with_embedding: int, ready: bool}
     */
    public function companyStatus(int $companyId): array
    {
        $indexed = KnowledgeChunk::query()->where('company_id', $companyId)->count();
        $withEmbedding = KnowledgeChunk::query()
            ->where('company_id', $companyId)
            ->whereNotNull('embedding')
            ->count();

        $enabled = (bool) config('knowledge.rag.enabled', true);
        $hasApiKey = (string) config('services.openai.api_key') !== '';

        return [
            'enabled' => $enabled,
            'indexed' => $indexed,
            'with_embedding' => $withEmbedding,
            'ready' => $enabled && $hasApiKey && $withEmbedding > 0,
        ];
    }
}
