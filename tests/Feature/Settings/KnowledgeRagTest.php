<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Models\KnowledgeChunk;
use App\Models\Product;
use App\Models\User;
use App\Services\Knowledge\KnowledgeEmbeddingIndexer;
use App\Support\TenantCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class KnowledgeRagTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['administrator', 'manager', 'employee'] as $role) {
            Role::findOrCreate($role);
        }

        TenantCompany::ensureExists();
        Config::set('knowledge.rag.enabled', true);
        Config::set('services.openai.api_key', 'test-key');
        Config::set('services.openai.base_url', 'https://api.openai.com/v1');
    }

    public function test_prompt_preview_uses_rag_when_chunks_match_query(): void
    {
        $companyId = TenantCompany::id();
        $product = Product::create([
            'company_id' => $companyId,
            'name' => 'Диван угловой',
            'description' => 'Мягкий диван для гостиной',
            'include_in_prompt' => true,
            'is_active' => true,
        ]);

        KnowledgeChunk::create([
            'company_id' => $companyId,
            'source_type' => KnowledgeChunk::TYPE_PRODUCT,
            'source_id' => $product->id,
            'content_text' => 'Диван угловой',
            'display_line' => '- Товар: Диван угловой',
            'content_hash' => hash('sha256', 'test'),
            'embedding' => [1.0, 0.0, 0.0],
        ]);

        Http::fake([
            'api.openai.com/v1/embeddings' => Http::response([
                'data' => [
                    ['index' => 0, 'embedding' => [1.0, 0.0, 0.0]],
                ],
            ]),
        ]);

        $admin = User::factory()->create();
        $admin->assignRole('administrator');

        $response = $this->actingAs($admin)->getJson(
            '/settings/knowledge/prompt-preview?company_id='.$companyId.'&query='.urlencode('сколько стоит диван'),
        );

        $response->assertOk();
        $response->assertJsonPath('used_rag', true);
        $this->assertStringContainsString('Диван угловой', (string) $response->json('text'));
        $this->assertStringContainsString('RAG', (string) $response->json('text'));
    }

    public function test_reindex_embeddings_indexes_active_products(): void
    {
        Http::fake([
            'api.openai.com/v1/embeddings' => Http::response([
                'data' => [
                    ['index' => 0, 'embedding' => [0.5, 0.5, 0.0]],
                ],
            ]),
        ]);

        $companyId = TenantCompany::id();
        $product = Product::create([
            'company_id' => $companyId,
            'name' => 'Стол обеденный',
            'include_in_prompt' => true,
            'is_active' => true,
        ]);

        $admin = User::factory()->create();
        $admin->assignRole('administrator');

        $response = $this->actingAs($admin)->postJson('/settings/knowledge/reindex-embeddings', [
            'company_id' => $companyId,
        ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('rag.with_embedding', 1);

        $this->assertDatabaseHas('knowledge_chunks', [
            'source_type' => KnowledgeChunk::TYPE_PRODUCT,
            'source_id' => $product->id,
        ]);
    }

    public function test_indexer_skips_unchanged_chunks(): void
    {
        Http::fake();

        $companyId = TenantCompany::id();
        $product = Product::create([
            'company_id' => $companyId,
            'name' => 'Кресло',
            'include_in_prompt' => true,
            'is_active' => true,
        ]);

        $indexer = app(KnowledgeEmbeddingIndexer::class);
        KnowledgeChunk::create([
            'company_id' => $companyId,
            'source_type' => KnowledgeChunk::TYPE_PRODUCT,
            'source_id' => $product->id,
            'content_text' => "Товар\nКресло",
            'display_line' => '- Товар: Кресло',
            'content_hash' => hash('sha256', "Товар\nКресло"),
            'embedding' => [1.0, 0.0],
        ]);

        $stats = $indexer->syncCompany($companyId);

        $this->assertSame(0, $stats['indexed']);
        $this->assertGreaterThanOrEqual(1, $stats['skipped']);
        Http::assertNothingSent();
    }
}
