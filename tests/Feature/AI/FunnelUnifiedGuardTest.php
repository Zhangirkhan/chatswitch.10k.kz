<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\Models\Chat;
use App\Services\Funnel\ChatFunnelCatalogBuilder;
use App\Services\Funnel\FunnelStageTransitionGuard;
use ReflectionClass;
use Tests\TestCase;

/**
 * Tests for FunnelStageTransitionGuard — forward-skip and rollback guard logic.
 *
 * Because ChatFunnelCatalogBuilder is final and cannot be mocked or subclassed,
 * we test the guard's core private helpers (isForwardSkipBlocked, isRollbackBlocked)
 * directly via reflection, passing the catalog as a plain array.
 */
final class FunnelUnifiedGuardTest extends TestCase
{
    private FunnelStageTransitionGuard $guard;

    private ReflectionClass $reflector;

    protected function setUp(): void
    {
        parent::setUp();
        // Build the guard with the real catalog builder (unused in reflection tests).
        $this->guard = app(FunnelStageTransitionGuard::class);
        $this->reflector = new ReflectionClass($this->guard);
    }

    private function fourStageCatalog(): array
    {
        return [
            [
                'id' => 10,
                'name' => 'Воронка',
                'description' => null,
                'color' => '#000',
                'stages' => [
                    ['id' => 100, 'name' => 'Этап 1', 'color' => '#000', 'position' => 1],
                    ['id' => 101, 'name' => 'Этап 2', 'color' => '#000', 'position' => 2],
                    ['id' => 102, 'name' => 'Этап 3', 'color' => '#000', 'position' => 3],
                    ['id' => 103, 'name' => 'Этап 4', 'color' => '#000', 'position' => 4],
                ],
            ],
        ];
    }

    private function makeChat(int $funnelId, int $stageId): Chat
    {
        $chat = new Chat();
        $chat->id = 1;
        $chat->company_id = 1;
        $chat->funnel_id = $funnelId;
        $chat->funnel_stage_id = $stageId;

        return $chat;
    }

    private function callForwardSkip(Chat $chat, int $funnelId, int $stageId, float $confidence): bool
    {
        $method = $this->reflector->getMethod('isForwardSkipBlocked');
        $method->setAccessible(true);

        return $method->invoke($this->guard, $chat, $this->fourStageCatalog(), $funnelId, $stageId, $confidence);
    }

    private function callRollbackBlocked(Chat $chat, int $funnelId, int $stageId, float $confidence): bool
    {
        $method = $this->reflector->getMethod('isRollbackBlocked');
        $method->setAccessible(true);

        return $method->invoke($this->guard, $chat, $this->fourStageCatalog(), $funnelId, $stageId, $confidence);
    }

    public function test_forward_skip_blocked_when_exceeds_max(): void
    {
        config(['funnel.ai.max_skip_stages' => 2]);
        config(['funnel.ai.skip_stages_min_confidence' => 0.90]);

        $chat = $this->makeChat(10, 100);
        // Jump from stage 100 (idx=0) to 103 (idx=3): skip count = 3 > max_skip=2.
        $blocked = $this->callForwardSkip($chat, 10, 103, 0.75);

        $this->assertTrue($blocked);
    }

    public function test_valid_one_step_transition_not_blocked(): void
    {
        config(['funnel.ai.max_skip_stages' => 2]);

        $chat = $this->makeChat(10, 100);
        // Jump from 100 to 101: skip = 1 ≤ max = 2.
        $blocked = $this->callForwardSkip($chat, 10, 101, 0.75);

        $this->assertFalse($blocked);
    }

    public function test_high_confidence_overrides_skip_limit(): void
    {
        config(['funnel.ai.max_skip_stages' => 2]);
        config(['funnel.ai.skip_stages_min_confidence' => 0.90]);

        $chat = $this->makeChat(10, 100);
        // Same 3-stage jump but confidence 0.95 >= 0.90 override threshold.
        $blocked = $this->callForwardSkip($chat, 10, 103, 0.95);

        $this->assertFalse($blocked);
    }

    public function test_backward_move_not_blocked_by_forward_skip(): void
    {
        config(['funnel.ai.max_skip_stages' => 2]);

        $chat = $this->makeChat(10, 103);
        // Backward move — forward skip guard should not apply.
        $blocked = $this->callForwardSkip($chat, 10, 100, 0.75);

        $this->assertFalse($blocked);
    }

    public function test_rollback_blocked_when_low_confidence(): void
    {
        config(['funnel.ai.rollback_min_confidence' => 0.85]);

        $chat = $this->makeChat(10, 103);
        $blocked = $this->callRollbackBlocked($chat, 10, 101, 0.70);

        $this->assertTrue($blocked);
    }

    public function test_rollback_allowed_with_high_confidence(): void
    {
        config(['funnel.ai.rollback_min_confidence' => 0.85]);

        $chat = $this->makeChat(10, 103);
        $blocked = $this->callRollbackBlocked($chat, 10, 101, 0.90);

        $this->assertFalse($blocked);
    }

    public function test_two_stage_skip_within_max_is_allowed(): void
    {
        config(['funnel.ai.max_skip_stages' => 2]);
        config(['funnel.ai.skip_stages_min_confidence' => 0.90]);

        $chat = $this->makeChat(10, 100);
        // 100 → 102: skip count = 2 = max_skip_stages — allowed.
        $blocked = $this->callForwardSkip($chat, 10, 102, 0.75);

        $this->assertFalse($blocked);
    }
}
