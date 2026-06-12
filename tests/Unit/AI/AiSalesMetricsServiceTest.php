<?php

declare(strict_types=1);

namespace Tests\Unit\AI;

use App\Models\Chat;
use App\Models\Company;
use App\Models\DealOutcome;
use App\Models\Message;
use App\Models\User;
use App\Services\AI\AiSalesMetricsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

final class AiSalesMetricsServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-06-12 12:00:00', 'Asia/Almaty'));
    }

    public function test_builds_qualification_and_budget_rates_for_ai_cohort(): void
    {
        $superAdmin = User::factory()->create([
            'is_super_admin' => true,
            'company_id' => null,
            'super_admin_scope' => 'global',
        ]);

        $company = Company::query()->create([
            'name' => 'Metrics Co',
            'slug' => 'metrics-co',
            'is_active' => true,
            'subscription_status' => 'active',
        ]);

        $qualifiedChat = Chat::factory()->create([
            'company_id' => $company->id,
            'ai_enabled' => true,
            'sales_state' => [
                'qualified' => true,
                'budget_known' => true,
                'decision_maker_known' => true,
            ],
        ]);

        $unqualifiedChat = Chat::factory()->create([
            'company_id' => $company->id,
            'ai_enabled' => true,
            'sales_state' => [
                'qualified' => false,
                'budget_known' => false,
                'decision_maker_known' => false,
            ],
        ]);

        foreach ([$qualifiedChat, $unqualifiedChat] as $chat) {
            Message::factory()->create([
                'chat_id' => $chat->id,
                'direction' => 'inbound',
                'message_timestamp' => now()->subDay(),
            ]);
        }

        $service = app(AiSalesMetricsService::class);
        $payload = $service->build($superAdmin, now()->subDays(30), now(), $company->id);

        $this->assertSame(2, $payload['summary']['cohort_size']);

        $qualification = collect($payload['kpis'])->firstWhere('key', 'qualification_rate');
        $budget = collect($payload['kpis'])->firstWhere('key', 'budget_capture_rate');
        $dm = collect($payload['kpis'])->firstWhere('key', 'dm_capture_rate');

        $this->assertNotNull($qualification);
        $this->assertSame(50.0, $qualification['percent']);
        $this->assertSame(1, $qualification['numerator']);
        $this->assertSame(2, $qualification['denominator']);

        $this->assertNotNull($budget);
        $this->assertSame(50.0, $budget['percent']);

        $this->assertNotNull($dm);
        $this->assertSame(50.0, $dm['percent']);
    }

    public function test_builds_close_rate_and_lost_reason_distribution(): void
    {
        $superAdmin = User::factory()->create([
            'is_super_admin' => true,
            'company_id' => null,
            'super_admin_scope' => 'global',
        ]);

        $company = Company::query()->create([
            'name' => 'Outcomes Co',
            'slug' => 'outcomes-co',
            'is_active' => true,
            'subscription_status' => 'active',
        ]);

        $chat = Chat::factory()->create([
            'company_id' => $company->id,
            'ai_enabled' => true,
        ]);

        Message::factory()->create([
            'chat_id' => $chat->id,
            'direction' => 'inbound',
            'message_timestamp' => now()->subDay(),
        ]);

        DealOutcome::query()->create([
            'company_id' => $company->id,
            'chat_id' => $chat->id,
            'won' => true,
            'reason' => 'быстро',
            'lead_grade' => 'A',
            'closed_at' => now()->subDay(),
        ]);

        DealOutcome::query()->create([
            'company_id' => $company->id,
            'chat_id' => $chat->id,
            'won' => false,
            'reason' => 'цена',
            'lead_grade' => 'B',
            'closed_at' => now()->subDays(2),
        ]);

        DealOutcome::query()->create([
            'company_id' => $company->id,
            'chat_id' => $chat->id,
            'won' => false,
            'reason' => 'цена',
            'lead_grade' => 'C',
            'closed_at' => now()->subDays(3),
        ]);

        $service = app(AiSalesMetricsService::class);
        $payload = $service->build($superAdmin, now()->subDays(30), now(), $company->id);

        $closeRate = collect($payload['kpis'])->firstWhere('key', 'close_rate');
        $this->assertNotNull($closeRate);
        $this->assertTrue($closeRate['sufficient_data']);
        $this->assertSame(33.3, $closeRate['percent']);
        $this->assertSame(1, $closeRate['numerator']);
        $this->assertSame(3, $closeRate['denominator']);

        $this->assertCount(1, $payload['lost_reasons']);
        $topLoss = $payload['lost_reasons'][0];
        $this->assertSame('цена', $topLoss['reason']);
        $this->assertSame(2, $topLoss['count']);

        $gradeA = collect($payload['win_rate_by_grade'])->firstWhere('grade', 'A');
        $this->assertNotNull($gradeA);
        $this->assertSame(100.0, $gradeA['percent']);
    }

    public function test_cohort_excludes_chats_without_ai_activity(): void
    {
        $superAdmin = User::factory()->create([
            'is_super_admin' => true,
            'company_id' => null,
            'super_admin_scope' => 'global',
        ]);

        $company = Company::query()->create([
            'name' => 'Filter Co',
            'slug' => 'filter-co',
            'is_active' => true,
            'subscription_status' => 'active',
        ]);

        $manualChat = Chat::factory()->create([
            'company_id' => $company->id,
            'ai_enabled' => false,
        ]);

        Message::factory()->create([
            'chat_id' => $manualChat->id,
            'direction' => 'inbound',
            'message_timestamp' => now()->subDay(),
        ]);

        $service = app(AiSalesMetricsService::class);
        $payload = $service->build($superAdmin, now()->subDays(30), now(), $company->id);

        $this->assertSame(0, $payload['summary']['cohort_size']);
    }
}
