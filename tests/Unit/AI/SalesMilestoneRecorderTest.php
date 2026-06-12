<?php

declare(strict_types=1);

namespace Tests\Unit\AI;

use App\Models\Chat;
use App\Models\Company;
use App\Models\SalesMilestone;
use App\Services\AI\ChatSalesStateService;
use App\Services\AI\SalesMilestoneRecorder;
use App\Support\AiFeatureFlags;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SalesMilestoneRecorderTest extends TestCase
{
    use RefreshDatabase;

    public function test_records_qualification_milestone_on_state_transition(): void
    {
        $company = Company::query()->create([
            'name' => 'Milestone Co',
            'slug' => 'milestone-co',
            'is_active' => true,
            'subscription_status' => 'active',
        ]);
        AiFeatureFlags::enable(AiFeatureFlags::SALES_STATE, $company->id);

        $chat = Chat::factory()->create([
            'company_id' => $company->id,
            'sales_state' => ['qualified' => false, 'budget_known' => false],
        ]);

        app(SalesMilestoneRecorder::class)->recordStateTransitions(
            $chat,
            ['qualified' => false, 'budget_known' => false],
            ['qualified' => true, 'budget_known' => true, 'score' => 70, 'grade' => 'B'],
        );

        $this->assertDatabaseHas('sales_milestones', [
            'chat_id' => $chat->id,
            'milestone' => SalesMilestone::MILESTONE_QUALIFIED,
        ]);
    }

    public function test_does_not_duplicate_one_time_milestones(): void
    {
        $company = Company::query()->create([
            'name' => 'Dup Co',
            'slug' => 'dup-co',
            'is_active' => true,
            'subscription_status' => 'active',
        ]);
        AiFeatureFlags::enable(AiFeatureFlags::SALES_STATE, $company->id);

        $chat = Chat::factory()->create(['company_id' => $company->id]);
        $recorder = app(SalesMilestoneRecorder::class);

        $recorder->record($chat, SalesMilestone::MILESTONE_QUALIFIED);
        $recorder->record($chat, SalesMilestone::MILESTONE_QUALIFIED);

        $this->assertSame(1, SalesMilestone::query()->where('chat_id', $chat->id)->count());
    }
}
