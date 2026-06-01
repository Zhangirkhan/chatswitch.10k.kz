<?php

declare(strict_types=1);

namespace App\Services\AI\Locale;

use App\Models\LocaleFewShotExample;
use App\Models\LocalePhraseChunk;
use App\Services\AI\OpenAiEmbeddingService;
use Illuminate\Support\Facades\Log;
use Throwable;

final class LocaleEmbeddingIndexer
{
    public function __construct(
        private readonly OpenAiEmbeddingService $embeddings,
        private readonly KazakhstanLocaleDetector $detector,
    ) {}

    /**
     * @return array{indexed: int, skipped: int, failed: int}
     */
    public function indexFewShots(?int $companyId = null): array
    {
        $stats = ['indexed' => 0, 'skipped' => 0, 'failed' => 0];

        $query = LocaleFewShotExample::query()->whereNull('embedding');
        if ($companyId !== null) {
            $query->where(function ($q) use ($companyId): void {
                $q->whereNull('company_id')->orWhere('company_id', $companyId);
            });
        }

        $query->chunkById(50, function ($examples) use (&$stats, $companyId): void {
            foreach ($examples as $example) {
                try {
                    $vector = $this->embeddings->embed(
                        (string) $example->user_text,
                        new \App\Services\AI\AiUsageOptions('background', $example->company_id ?? $companyId),
                    );
                    if ($vector === []) {
                        $stats['skipped']++;

                        continue;
                    }
                    $profile = $this->detector->detect((string) $example->user_text);
                    $example->forceFill([
                        'embedding' => $vector,
                        'language_profile' => $example->language_profile ?? $profile->toArray(),
                        'formality' => $example->formality ?? $profile->formality,
                    ])->save();
                    $stats['indexed']++;
                } catch (Throwable $e) {
                    Log::warning('[locale-index] few-shot failed', ['id' => $example->id, 'error' => $e->getMessage()]);
                    $stats['failed']++;
                }
            }
        });

        return $stats;
    }

    /**
     * @return array{indexed: int, skipped: int, failed: int}
     */
    public function indexPhrases(?int $companyId = null): array
    {
        $stats = ['indexed' => 0, 'skipped' => 0, 'failed' => 0];

        $query = LocalePhraseChunk::query()->whereNull('embedding');
        if ($companyId !== null) {
            $query->where(function ($q) use ($companyId): void {
                $q->whereNull('company_id')->orWhere('company_id', $companyId);
            });
        }

        $query->chunkById(50, function ($chunks) use (&$stats, $companyId): void {
            foreach ($chunks as $chunk) {
                try {
                    $text = trim((string) $chunk->phrase);
                    if ($text === '') {
                        $stats['skipped']++;

                        continue;
                    }
                    $vector = $this->embeddings->embed(
                        $text,
                        new \App\Services\AI\AiUsageOptions('background', $chunk->company_id ?? $companyId),
                    );
                    if ($vector === []) {
                        $stats['skipped']++;

                        continue;
                    }
                    $chunk->forceFill(['embedding' => $vector])->save();
                    $stats['indexed']++;
                } catch (Throwable $e) {
                    Log::warning('[locale-index] phrase failed', ['id' => $chunk->id, 'error' => $e->getMessage()]);
                    $stats['failed']++;
                }
            }
        });

        return $stats;
    }
}
