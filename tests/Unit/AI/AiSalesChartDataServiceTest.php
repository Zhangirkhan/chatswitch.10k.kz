<?php

declare(strict_types=1);

namespace Tests\Unit\AI;

use App\Models\Chat;
use App\Models\DealOutcome;
use App\Services\AI\AiSalesChartDataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AiSalesChartDataServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_outcomes_daily_fills_all_days_in_period(): void
    {
        $company = $this->createTenantCompany(['name' => 'Chart Co', 'slug' => 'chart-co']);
        $from = now()->subDays(2)->startOfDay();
        $to = now()->endOfDay();

        $chat = Chat::factory()->create(['company_id' => $company->id]);

        DealOutcome::query()->create([
            'company_id' => $company->id,
            'chat_id' => $chat->id,
            'won' => true,
            'reason' => 'price',
            'closed_at' => now()->subDay(),
        ]);

        DealOutcome::query()->create([
            'company_id' => $company->id,
            'chat_id' => $chat->id + 1,
            'won' => false,
            'reason' => 'timing',
            'closed_at' => now(),
        ]);

        $charts = app(AiSalesChartDataService::class)->build(
            [$company->id],
            [$chat->id],
            $from,
            $to,
            [],
            [],
            [],
            ['top_objections' => [], 'top_winning_responses' => [], 'top_losing_responses' => []],
            [],
            [],
            $company->id,
        );

        $this->assertCount(3, $charts['outcomes_daily']['labels']);
        $this->assertSame(1, array_sum($charts['outcomes_daily']['won']));
        $this->assertSame(1, array_sum($charts['outcomes_daily']['lost']));
        $this->assertArrayHasKey('funnel', $charts);
    }
}
