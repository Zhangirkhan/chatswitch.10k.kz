<?php

declare(strict_types=1);

namespace Tests\Unit\AI;

use App\Models\Chat;
use App\Models\Company;
use App\Services\AI\WinProbabilityService;
use App\Support\AiFeatureFlags;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class WinProbabilityServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_computes_probability_and_persists_score(): void
    {
        $company = Company::query()->create([
            'name' => 'Win Co',
            'slug' => 'win-co',
            'is_active' => true,
            'subscription_status' => 'active',
        ]);
        AiFeatureFlags::enable(AiFeatureFlags::SALES_STATE, $company->id);

        $chat = Chat::factory()->create([
            'company_id' => $company->id,
            'sales_state' => [
                'qualified' => true,
                'budget_known' => true,
                'requirements_known' => true,
                'timeline_known' => true,
                'decision_maker_known' => true,
                'score' => 80,
                'grade' => 'A',
                'objections_open' => [],
                'next_action' => 'present_offer',
            ],
        ]);

        $result = app(WinProbabilityService::class)->compute($chat);

        $this->assertGreaterThanOrEqual(5, $result['win_probability']);
        $this->assertLessThanOrEqual(95, $result['win_probability']);
        $this->assertDatabaseHas('win_probability_scores', [
            'chat_id' => $chat->id,
            'probability' => $result['win_probability'],
        ]);
    }
}
