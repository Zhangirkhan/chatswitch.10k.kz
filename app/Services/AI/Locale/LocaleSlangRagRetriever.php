<?php

declare(strict_types=1);

namespace App\Services\AI\Locale;

use App\Models\LocalePhraseChunk;
use App\Services\AI\OpenAiEmbeddingService;
use App\Support\VectorCosine;
use Illuminate\Support\Facades\Log;
use Throwable;

final class LocaleSlangRagRetriever
{
    public function __construct(
        private readonly OpenAiEmbeddingService $embeddings,
    ) {}

    public function shouldRetrieve(KazakhstanLocaleProfile $profile): bool
    {
        if (! config('locale_assistant.rag.enabled', false)) {
            return false;
        }

        if ((string) config('services.openai.api_key') === '') {
            return false;
        }

        if ($profile->formality === KazakhstanLocaleProfile::FORMALITY_FORMAL) {
            return false;
        }

        $threshold = (float) config('locale_assistant.rag.slang_score_threshold', 0.35);

        return $profile->slangScore >= $threshold
            || $profile->formality === KazakhstanLocaleProfile::FORMALITY_CASUAL;
    }

    /**
     * @return list<string>
     */
    public function retrieveLines(string $query, KazakhstanLocaleProfile $profile, ?int $companyId = null): array
    {
        if (! $this->shouldRetrieve($profile) || trim($query) === '') {
            return [];
        }

        $chunks = LocalePhraseChunk::query()
            ->whereNotNull('embedding')
            ->when($companyId !== null, function ($q) use ($companyId): void {
                $q->where(function ($inner) use ($companyId): void {
                    $inner->whereNull('company_id')->orWhere('company_id', $companyId);
                });
            })
            ->get();

        if ($chunks->isEmpty()) {
            return [];
        }

        try {
            $queryVector = $this->embeddings->embed($query);
        } catch (Throwable $e) {
            Log::warning('[locale-rag] embedding failed', ['error' => $e->getMessage()]);

            return [];
        }

        if ($queryVector === []) {
            return [];
        }

        $minSimilarity = (float) config('locale_assistant.rag.min_similarity', 0.28);
        $topK = (int) config('locale_assistant.rag.top_k', 6);
        $scored = [];

        foreach ($chunks as $chunk) {
            $vector = $chunk->embedding;
            if (! is_array($vector) || $vector === []) {
                continue;
            }

            $score = VectorCosine::similarity(
                $queryVector,
                array_map(static fn ($v): float => (float) $v, $vector),
            );

            if ($score < $minSimilarity) {
                continue;
            }

            $line = trim((string) $chunk->phrase);
            $meaning = trim((string) $chunk->meaning_ru);
            if ($meaning !== '') {
                $line .= ' — '.$meaning;
            }

            $scored[] = ['line' => $line, 'score' => $score];
        }

        usort($scored, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return array_values(array_filter(array_map(
            static fn (array $row): string => $row['line'],
            array_slice($scored, 0, $topK),
        ), static fn (string $line): bool => $line !== ''));
    }

    public function formatBlock(array $lines): string
    {
        if ($lines === []) {
            return '';
        }

        return "Локальные выражения и сленг (контекст, не обязательно использовать):\n- ".implode("\n- ", $lines);
    }
}
