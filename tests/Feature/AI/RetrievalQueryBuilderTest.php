<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\Models\Chat;
use App\Services\AI\ActiveTopicDetector;
use App\Services\AI\Retrieval\RetrievalQueryBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for RetrievalQueryBuilder — context-aware RAG query enrichment.
 */
final class RetrievalQueryBuilderTest extends TestCase
{
    use RefreshDatabase;

    private RetrievalQueryBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->builder = app(RetrievalQueryBuilder::class);
    }

    public function test_substantive_message_returned_unchanged(): void
    {
        $chat = $this->makeChat();
        $body = 'Хочу купить диван трёхместный, серый, бюджет до 150 000 тенге, нужна доставка по Алматы';

        $result = $this->builder->build($body, $chat);

        $this->assertSame($body, $result);
    }

    public function test_vague_followup_enriched_with_active_topic(): void
    {
        $chat = $this->makeChat(['active_topic' => 'доставка по Алматы, диваны']);

        $result = $this->builder->build('Уточнили?', $chat);

        $this->assertStringContainsString('доставка', $result);
        $this->assertNotSame('Уточнили?', $result);
    }

    public function test_domain_hint_stripped_from_topic(): void
    {
        $chat = $this->makeChat(['active_topic' => '[delivery] доставка по Астане']);

        $result = $this->builder->build('Ну что?', $chat);

        $this->assertStringNotContainsString('[delivery]', $result);
        $this->assertStringContainsString('доставка', $result);
    }

    public function test_empty_trigger_with_no_topic_returns_fallback(): void
    {
        $chat = $this->makeChat();

        $result = $this->builder->build('', $chat);

        $this->assertNotEmpty($result);
    }

    public function test_short_message_without_topic_does_not_crash(): void
    {
        $chat = $this->makeChat(['active_topic' => null]);

        $result = $this->builder->build('Да', $chat);

        $this->assertIsString($result);
        $this->assertNotEmpty($result);
    }

    /** @param  array<string, mixed>  $attributes */
    private function makeChat(array $attributes = []): Chat
    {
        $chat = new Chat();
        $chat->id = 0; // Non-existent ID avoids real DB queries for chat
        $chat->company_id = 1;
        $chat->active_topic = $attributes['active_topic'] ?? null;

        return $chat;
    }
}
