<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\AI\Locale\LocaleEmbeddingIndexer;
use Illuminate\Console\Command;

final class LocaleIndexEmbeddingsCommand extends Command
{
    protected $signature = 'locale:index-embeddings {company_id? : Optional company ID}';

    protected $description = 'Index embeddings for locale few-shot examples and slang phrases';

    public function handle(LocaleEmbeddingIndexer $indexer): int
    {
        if ((string) config('services.openai.api_key') === '') {
            $this->warn('OPENAI_API_KEY not set — skipping.');

            return self::SUCCESS;
        }

        $companyId = $this->argument('company_id') !== null
            ? (int) $this->argument('company_id')
            : null;

        $fewShot = $indexer->indexFewShots($companyId);
        $phrases = $indexer->indexPhrases($companyId);

        $this->info(sprintf(
            'Few-shot: indexed=%d skipped=%d failed=%d',
            $fewShot['indexed'],
            $fewShot['skipped'],
            $fewShot['failed'],
        ));
        $this->info(sprintf(
            'Phrases: indexed=%d skipped=%d failed=%d',
            $phrases['indexed'],
            $phrases['skipped'],
            $phrases['failed'],
        ));

        return self::SUCCESS;
    }
}
