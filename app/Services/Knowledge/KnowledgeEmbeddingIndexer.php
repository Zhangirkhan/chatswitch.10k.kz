<?php

declare(strict_types=1);

namespace App\Services\Knowledge;

use App\Models\KnowledgeChunk;
use App\Models\KnowledgeRule;
use App\Models\Product;
use App\Models\Service;
use App\Services\AI\OpenAiEmbeddingService;
use Illuminate\Support\Facades\Log;
use Throwable;

final class KnowledgeEmbeddingIndexer
{
    public function __construct(
        private readonly KnowledgeChunkFactory $chunkFactory,
        private readonly OpenAiEmbeddingService $embeddings,
    ) {}

    /**
     * @return array{indexed: int, skipped: int, removed: int, failed: int}
     */
    public function syncCompany(int $companyId): array
    {
        $stats = ['indexed' => 0, 'skipped' => 0, 'removed' => 0, 'failed' => 0];

        foreach (Product::query()->where('company_id', $companyId)->get() as $product) {
            $this->bumpStats($stats, $this->syncProduct($product));
        }

        foreach (Service::query()->where('company_id', $companyId)->get() as $service) {
            $this->bumpStats($stats, $this->syncService($service));
        }

        foreach (KnowledgeRule::query()->where('company_id', $companyId)->get() as $rule) {
            $this->bumpStats($stats, $this->syncRule($rule));
        }

        return $stats;
    }

    public function syncProduct(Product $product): ?string
    {
        $payload = $this->chunkFactory->fromProduct($product);
        if ($payload === null) {
            $this->removeChunk(KnowledgeChunk::TYPE_PRODUCT, $product->id);

            return null;
        }

        return $this->upsertChunk(
            $product->company_id,
            KnowledgeChunk::TYPE_PRODUCT,
            $product->id,
            $payload['content_text'],
            $payload['display_line'],
        );
    }

    public function syncService(Service $service): ?string
    {
        $payload = $this->chunkFactory->fromService($service);
        if ($payload === null) {
            $this->removeChunk(KnowledgeChunk::TYPE_SERVICE, $service->id);

            return null;
        }

        return $this->upsertChunk(
            $service->company_id,
            KnowledgeChunk::TYPE_SERVICE,
            $service->id,
            $payload['content_text'],
            $payload['display_line'],
        );
    }

    public function syncRule(KnowledgeRule $rule): ?string
    {
        $payload = $this->chunkFactory->fromRule($rule);
        if ($payload === null) {
            $this->removeChunk(KnowledgeChunk::TYPE_RULE, $rule->id);

            return null;
        }

        return $this->upsertChunk(
            $rule->company_id,
            KnowledgeChunk::TYPE_RULE,
            $rule->id,
            $payload['content_text'],
            $payload['display_line'],
        );
    }

    private function upsertChunk(
        int $companyId,
        string $sourceType,
        int $sourceId,
        string $contentText,
        string $displayLine,
    ): string {
        $contentHash = hash('sha256', $contentText);

        $existing = KnowledgeChunk::query()
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->first();

        if (
            $existing !== null
            && $existing->content_hash === $contentHash
            && is_array($existing->embedding)
            && $existing->embedding !== []
        ) {
            return 'skipped';
        }

        if ((string) config('services.openai.api_key') === '') {
            KnowledgeChunk::query()->updateOrCreate(
                [
                    'source_type' => $sourceType,
                    'source_id' => $sourceId,
                ],
                [
                    'company_id' => $companyId,
                    'content_text' => $contentText,
                    'display_line' => $displayLine,
                    'content_hash' => $contentHash,
                    'embedding' => null,
                ],
            );

            return 'failed';
        }

        try {
            $vector = $this->embeddings->embed($contentText, new \App\Services\AI\AiUsageOptions('background', $companyId));
        } catch (Throwable $e) {
            Log::warning('[knowledge-rag] chunk embedding failed', [
                'company_id' => $companyId,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'error' => $e->getMessage(),
            ]);

            return 'failed';
        }

        KnowledgeChunk::query()->updateOrCreate(
            [
                'source_type' => $sourceType,
                'source_id' => $sourceId,
            ],
            [
                'company_id' => $companyId,
                'content_text' => $contentText,
                'display_line' => $displayLine,
                'content_hash' => $contentHash,
                'embedding' => $vector,
            ],
        );

        return 'indexed';
    }

    private function removeChunk(string $sourceType, int $sourceId): void
    {
        KnowledgeChunk::query()
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->delete();
    }

    /**
     * @param  array{indexed: int, skipped: int, removed: int, failed: int}  $stats
     */
    private function bumpStats(array &$stats, ?string $result): void
    {
        if ($result === null) {
            $stats['removed']++;

            return;
        }

        if (isset($stats[$result])) {
            $stats[$result]++;
        }
    }
}
