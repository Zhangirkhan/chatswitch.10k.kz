<?php

declare(strict_types=1);

namespace Tests\Unit\Funnel;

use App\Models\Chat;
use App\Models\Company;
use App\Models\Funnel;
use App\Models\FunnelStage;
use App\Services\Funnel\FunnelStageTransitionGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class FunnelStageTransitionGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_blocks_rollback_without_high_confidence(): void
    {
        $company = Company::create(['name' => 'Co']);
        $funnel = Funnel::create(['company_id' => $company->id, 'name' => 'Sales', 'is_active' => true, 'position' => 1]);
        $stageA = FunnelStage::create(['funnel_id' => $funnel->id, 'name' => 'A', 'position' => 1, 'is_active' => true]);
        $stageB = FunnelStage::create(['funnel_id' => $funnel->id, 'name' => 'B', 'position' => 2, 'is_active' => true]);

        $chat = Chat::factory()->create([
            'company_id' => $company->id,
            'funnel_id' => $funnel->id,
            'funnel_stage_id' => $stageB->id,
        ]);

        $guard = app(FunnelStageTransitionGuard::class);

        $this->assertFalse($guard->canMove($chat, $funnel->id, $stageA->id, 0.7));
        $this->assertTrue($guard->canMove($chat, $funnel->id, $stageA->id, 0.9));
    }
}
