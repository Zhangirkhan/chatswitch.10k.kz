<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Company;
use App\Services\Knowledge\KnowledgeEmbeddingIndexer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

final class IndexKnowledgeEmbeddingsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct(public readonly int $companyId) {}

    public function handle(KnowledgeEmbeddingIndexer $indexer): void
    {
        if (! config('knowledge.rag.enabled', true)) {
            return;
        }

        if (! Company::query()->whereKey($this->companyId)->exists()) {
            return;
        }

        try {
            $stats = $indexer->syncCompany($this->companyId);
            Log::info('[knowledge-rag] company indexed', [
                'company_id' => $this->companyId,
                'stats' => $stats,
            ]);
        } catch (\Throwable $e) {
            Log::warning('[knowledge-rag] company indexing failed', [
                'company_id' => $this->companyId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
