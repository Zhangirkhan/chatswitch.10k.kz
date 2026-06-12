<?php

declare(strict_types=1);

namespace Tests\Unit\Knowledge;

use App\Models\Company;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeRetrievalLog;
use App\Services\Knowledge\KnowledgeQualityScoreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class KnowledgeQualityScoreServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_computes_and_persists_chunk_quality_scores(): void
    {
        $company = $this->createTenantCompany(['name' => 'KQS Co', 'slug' => 'kqs-co']);

        $chunk = KnowledgeChunk::query()->create([
            'company_id' => $company->id,
            'source_type' => KnowledgeChunk::TYPE_RULE,
            'source_id' => 1,
            'display_line' => '- Test rule',
            'content_text' => 'Test rule content',
            'content_hash' => hash('sha256', 'test'),
            'embedding' => [0.1, 0.2, 0.3],
        ]);

        KnowledgeRetrievalLog::query()->create([
            'company_id' => $company->id,
            'chunk_id' => $chunk->id,
            'similarity' => 0.85,
            'domain' => null,
        ]);

        $updated = app(KnowledgeQualityScoreService::class)->computeForCompany($company->id);

        $this->assertSame(1, $updated);
        $this->assertDatabaseHas('knowledge_chunk_stats', [
            'chunk_id' => $chunk->id,
            'retrieval_count' => 1,
        ]);

        $chunk->refresh();
        $this->assertNotNull($chunk->quality_score);
    }
}
