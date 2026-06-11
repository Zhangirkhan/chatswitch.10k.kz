<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\Models\AiOrchestratorAction;
use App\Models\AiOrchestratorRun;
use App\Models\Chat;
use App\Models\Message;
use App\Support\TenantCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class ActionStatusesTest extends TestCase
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
     * T1: AiOrchestratorAction constants cover all necessary statuses.
     */
    public function test_action_model_has_skipped_status(): void
    {
        $this->assertEquals('skipped', AiOrchestratorAction::STATUS_SKIPPED);
        $this->assertEquals('done', AiOrchestratorAction::STATUS_DONE);
        $this->assertEquals('failed', AiOrchestratorAction::STATUS_FAILED);
        $this->assertEquals('pending', AiOrchestratorAction::STATUS_PENDING);
    }

    /**
     * T3: A skipped action (e.g., move_funnel_stage guard rejection) should be
     * stored with STATUS_SKIPPED and a non-null error/reason field.
     */
    public function test_skipped_action_is_stored_with_skipped_status(): void
    {
        $chat = Chat::factory()->create(['company_id' => TenantCompany::id()]);
        $run = AiOrchestratorRun::factory()->create([
            'company_id' => TenantCompany::id(),
            'chat_id' => $chat->id,
            'status' => AiOrchestratorRun::STATUS_RUNNING,
        ]);

        // Simulate what AiFunnelActionExecutor::runAction does when skipped.
        $action = AiOrchestratorAction::create([
            'ai_orchestrator_run_id' => $run->id,
            'company_id' => TenantCompany::id(),
            'chat_id' => $chat->id,
            'type' => 'move_funnel_stage',
            'status' => AiOrchestratorAction::STATUS_PENDING,
            'payload' => ['funnel_stage_id' => 999],
        ]);

        $result = ['skipped' => true, 'reason' => 'already_on_stage'];

        $action->forceFill([
            'status' => AiOrchestratorAction::STATUS_SKIPPED,
            'result' => $result,
            'error' => $result['reason'],
        ])->save();

        $this->assertDatabaseHas('ai_orchestrator_actions', [
            'id' => $action->id,
            'status' => 'skipped',
        ]);

        $fresh = $action->fresh();
        $this->assertEquals('already_on_stage', $fresh->error);
    }
}
