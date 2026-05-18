<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\Knowledge\KnowledgeEmbeddingIndexer;
use Illuminate\Console\Command;

final class IndexKnowledgeEmbeddingsCommand extends Command
{
    protected $signature = 'knowledge:index-embeddings {company_id? : ID компании}';

    protected $description = 'Индексирует embeddings для базы знаний (RAG)';

    public function handle(KnowledgeEmbeddingIndexer $indexer): int
    {
        if (! config('knowledge.rag.enabled', true)) {
            $this->warn('RAG отключён (KNOWLEDGE_RAG_ENABLED=false).');

            return self::SUCCESS;
        }

        $companyId = $this->argument('company_id');
        $query = Company::query()->orderBy('id');

        if ($companyId !== null) {
            $query->whereKey((int) $companyId);
        }

        $totals = ['indexed' => 0, 'skipped' => 0, 'removed' => 0, 'failed' => 0];

        $query->chunkById(50, function ($companies) use ($indexer, &$totals): void {
            foreach ($companies as $company) {
                $stats = $indexer->syncCompany($company->id);
                foreach ($stats as $key => $value) {
                    $totals[$key] += $value;
                }
                $this->line(sprintf(
                    'Company #%d: indexed=%d skipped=%d removed=%d failed=%d',
                    $company->id,
                    $stats['indexed'],
                    $stats['skipped'],
                    $stats['removed'],
                    $stats['failed'],
                ));
            }
        });

        $this->info(sprintf(
            'Done. indexed=%d skipped=%d removed=%d failed=%d',
            $totals['indexed'],
            $totals['skipped'],
            $totals['removed'],
            $totals['failed'],
        ));

        return self::SUCCESS;
    }
}
