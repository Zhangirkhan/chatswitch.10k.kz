<?php

declare(strict_types=1);

namespace Tests\Unit\AI;

use App\Models\KnowledgeRule;
use App\Services\AI\Orchestrator\ClientSituation;
use App\Services\AI\Orchestrator\DeescalationReplyBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class DeescalationReplyBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_builds_delay_deescalation_reply(): void
    {
        $builder = app(DeescalationReplyBuilder::class);
        $situation = new ClientSituation(ClientSituation::SITUATION_DELAY, 1, 0.8);

        $reply = $builder->build($situation, 'Где заказ?', 1, 0, false);

        $this->assertStringContainsString('задерж', mb_strtolower($reply));
    }

    public function test_merges_warranty_knowledge_for_quality_issue(): void
    {
        $company = $this->createTenantCompany(['slug' => 'conflict-warranty-test']);

        KnowledgeRule::query()->create([
            'company_id' => $company->id,
            'title' => 'Warranty',
            'type' => 'warranty',
            'content' => 'Гарантия 2 года на монтаж.',
            'priority' => 1,
            'is_active' => true,
            'include_in_prompt' => true,
        ]);

        $builder = app(DeescalationReplyBuilder::class);
        $situation = new ClientSituation(ClientSituation::SITUATION_QUALITY, 1, 0.8);

        $reply = $builder->build($situation, 'Брак на двери', $company->id, 0, false);

        $this->assertStringContainsString('Гарантия 2 года', $reply);
    }
}
