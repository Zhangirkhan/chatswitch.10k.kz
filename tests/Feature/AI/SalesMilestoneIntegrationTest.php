<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\Models\Chat;
use App\Models\Company;
use App\Models\DealOutcome;
use App\Models\SalesMilestone;
use App\Services\AI\ChatSalesStateService;
use App\Services\AI\ObjectionIntelligenceService;
use App\Support\AiFeatureFlags;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SalesMilestoneIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_update_from_facts_records_milestone_and_win_probability(): void
    {
        $company = Company::query()->create([
            'name' => 'Integrate Co',
            'slug' => 'integrate-co',
            'is_active' => true,
            'subscription_status' => 'active',
        ]);
        AiFeatureFlags::enable(AiFeatureFlags::SALES_STATE, $company->id);

        $chat = Chat::factory()->create([
            'company_id' => $company->id,
            'sales_state' => [
                'qualified' => false,
                'budget_known' => false,
                'requirements_known' => false,
                'timeline_known' => false,
                'decision_maker_known' => false,
                'objections_open' => [],
                'next_action' => ChatSalesStateService::NA_QUALIFY,
            ],
        ]);

        app(ChatSalesStateService::class)->updateFromFacts($chat, [
            'budget' => '500000',
            'requirements' => 'инструмент для дома',
        ]);

        $this->assertDatabaseHas('sales_milestones', [
            'chat_id' => $chat->id,
            'milestone' => SalesMilestone::MILESTONE_BUDGET_CAPTURED,
        ]);
        $this->assertDatabaseHas('win_probability_scores', [
            'chat_id' => $chat->id,
        ]);
    }

    public function test_objection_intelligence_clusters_deal_outcomes(): void
    {
        $company = Company::query()->create([
            'name' => 'Objection Co',
            'slug' => 'objection-co',
            'is_active' => true,
            'subscription_status' => 'active',
        ]);

        DealOutcome::query()->create([
            'company_id' => $company->id,
            'chat_id' => Chat::factory()->create(['company_id' => $company->id])->id,
            'won' => false,
            'reason' => 'дорого',
            'objections_at_close' => 'цена слишком высокая',
            'source' => 'manual',
            'closed_at' => now(),
        ]);

        $payload = app(ObjectionIntelligenceService::class)->buildForCompany($company->id);

        $this->assertNotEmpty($payload['top_objections']);
        $this->assertSame('price', $payload['top_objections'][0]['label']);
    }
}
