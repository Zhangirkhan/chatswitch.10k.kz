<?php

declare(strict_types=1);

namespace Tests\Unit\AI;

use App\Models\Chat;
use App\Models\Company;
use App\Services\AI\NextBestActionEngine;
use App\Services\AI\ChatSalesStateService;
use App\Support\AiFeatureFlags;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class NextBestActionEngineTest extends TestCase
{
    use RefreshDatabase;

    public function test_recommends_ask_budget_when_budget_unknown(): void
    {
        $company = Company::query()->create([
            'name' => 'NBA Co',
            'slug' => 'nba-co',
            'is_active' => true,
            'subscription_status' => 'active',
        ]);
        AiFeatureFlags::enable(AiFeatureFlags::SALES_STATE, $company->id);

        $chat = Chat::factory()->create([
            'company_id' => $company->id,
            'sales_state' => [
                'qualified' => false,
                'budget_known' => false,
                'requirements_known' => true,
                'next_action' => ChatSalesStateService::NA_ASK_BUDGET,
                'missing_fields' => ['бюджет'],
            ],
        ]);

        $result = app(NextBestActionEngine::class)->compute($chat);

        $this->assertSame(ChatSalesStateService::NA_ASK_BUDGET, $result['next_best_action']);
        $this->assertSame('confirm_budget', $result['goal']);
        $this->assertGreaterThan(0.2, $result['confidence']);
    }
}
