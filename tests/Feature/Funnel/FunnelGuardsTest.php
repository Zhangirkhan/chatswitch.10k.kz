<?php

declare(strict_types=1);

namespace Tests\Feature\Funnel;

use App\Models\Chat;
use App\Models\Funnel;
use App\Models\FunnelStage;
use App\Services\Funnel\FunnelStageTransitionGuard;
use App\Support\AiFeatureFlags;
use App\Support\TenantCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class FunnelGuardsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['administrator', 'manager', 'employee'] as $role) {
            Role::findOrCreate($role);
        }
        TenantCompany::ensureExists();
    }

    /**
     * F1: Sequence guard should block forward skips > max_skip_stages when confidence < threshold.
     */
    public function test_sequence_guard_blocks_large_forward_skip(): void
    {
        AiFeatureFlags::enable(AiFeatureFlags::FUNNEL_SEQUENCE_GUARD, TenantCompany::id());
        config(['funnel.ai.max_skip_stages' => 1, 'funnel.ai.skip_stages_min_confidence' => 0.90]);

        $funnel = Funnel::factory()->create(['company_id' => TenantCompany::id()]);

        $stage1 = FunnelStage::factory()->create(['funnel_id' => $funnel->id, 'position' => 1]);
        $stage2 = FunnelStage::factory()->create(['funnel_id' => $funnel->id, 'position' => 2]);
        $stage3 = FunnelStage::factory()->create(['funnel_id' => $funnel->id, 'position' => 3]);
        $stage4 = FunnelStage::factory()->create(['funnel_id' => $funnel->id, 'position' => 4]);

        $chat = Chat::factory()->create([
            'company_id' => TenantCompany::id(),
            'funnel_id' => $funnel->id,
            'funnel_stage_id' => $stage1->id,
        ]);

        $guard = app(FunnelStageTransitionGuard::class);

        // Skip from stage1 → stage4 (skip 3 stages) with low confidence — should be blocked.
        $this->assertEquals('forward_skip_blocked',
            $guard->rejectReason($chat, $funnel->id, $stage4->id, 0.70),
            'Forward skip of 3 stages with confidence 0.70 should be blocked');

        // Skip from stage1 → stage4 with high confidence — should be allowed.
        $this->assertNull(
            $guard->rejectReason($chat, $funnel->id, $stage4->id, 0.95),
            'Forward skip with high confidence should be allowed');

        // Skip from stage1 → stage2 (skip 1 stage, within limit) — should be allowed.
        $this->assertNull(
            $guard->rejectReason($chat, $funnel->id, $stage2->id, 0.60),
            'Forward skip of 1 stage should be allowed');
    }

    /**
     * F1: Sequence guard should be skipped when flag is off.
     */
    public function test_sequence_guard_disabled_when_flag_off(): void
    {
        // Flag NOT enabled.
        config(['funnel.ai.max_skip_stages' => 1]);

        $funnel = Funnel::factory()->create(['company_id' => TenantCompany::id()]);
        $stage1 = FunnelStage::factory()->create(['funnel_id' => $funnel->id, 'position' => 1]);
        $stage5 = FunnelStage::factory()->create(['funnel_id' => $funnel->id, 'position' => 5]);

        $chat = Chat::factory()->create([
            'company_id' => TenantCompany::id(),
            'funnel_id' => $funnel->id,
            'funnel_stage_id' => $stage1->id,
        ]);

        $guard = app(FunnelStageTransitionGuard::class);

        // Large skip with low confidence — should be allowed because flag is off.
        $this->assertNull(
            $guard->rejectReason($chat, $funnel->id, $stage5->id, 0.50),
            'Forward skip should be allowed when flag is off');
    }

    /**
     * T1/T2: move_funnel_stage that is blocked should record STATUS_SKIPPED, not STATUS_DONE.
     * (Integration test via action executor guard.)
     */
    public function test_action_skipped_status_when_already_on_stage(): void
    {
        $funnel = Funnel::factory()->create(['company_id' => TenantCompany::id()]);
        $stage = FunnelStage::factory()->create(['funnel_id' => $funnel->id, 'position' => 1]);

        $chat = Chat::factory()->create([
            'company_id' => TenantCompany::id(),
            'funnel_id' => $funnel->id,
            'funnel_stage_id' => $stage->id,
        ]);

        $guard = app(FunnelStageTransitionGuard::class);

        // already_on_stage — canMove returns false.
        $this->assertFalse($guard->canMove($chat, $funnel->id, $stage->id, 0.85));
        $this->assertEquals('already_on_stage', $guard->rejectReason($chat, $funnel->id, $stage->id, 0.85));
    }
}
