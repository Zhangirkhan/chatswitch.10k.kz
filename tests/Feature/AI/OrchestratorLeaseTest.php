<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\Models\AiOrchestratorRun;
use App\Models\Chat;
use App\Models\Funnel;
use App\Models\FunnelAiScenario;
use App\Models\Message;
use App\Models\WhatsappSession;
use App\Services\AI\AiFunnelOrchestratorService;
use App\Support\TenantCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class OrchestratorLeaseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['administrator', 'manager', 'employee'] as $role) {
            Role::findOrCreate($role);
        }
        TenantCompany::ensureExists();
        config(['ai.orchestrator_lease_timeout_minutes' => 5]);
    }

    /**
     * C5: A RUNNING run that has been stuck longer than the lease timeout should be
     * reclaimed so the orchestrator can retry.
     */
    public function test_expired_running_lease_is_reclaimed(): void
    {
        $chat = Chat::factory()->create(['company_id' => TenantCompany::id()]);
        $trigger = Message::factory()->create([
            'chat_id' => $chat->id,
            'direction' => 'inbound',
        ]);

        // Simulate a run that has been RUNNING for 10 minutes (> 5 min lease).
        $run = AiOrchestratorRun::create([
            'company_id' => TenantCompany::id(),
            'chat_id' => $chat->id,
            'trigger_message_id' => $trigger->id,
            'funnel_id' => null,
            'funnel_stage_id' => null,
            'status' => AiOrchestratorRun::STATUS_RUNNING,
            'started_at' => now()->subMinutes(10),
        ]);

        // The orchestrator should reclaim the dead lease and reset to PENDING.
        // We simulate just the reclaim step by calling run() and checking state.
        // Since the scenario is missing, the orchestrator will exit after reclaim.
        $orchestrator = app(AiFunnelOrchestratorService::class);
        $orchestrator->run($chat->id, $trigger->id);

        $run->refresh();
        // After reclaim attempt, status should NOT still be RUNNING
        // (either PENDING was set, then claim succeeded and the run proceeded).
        $this->assertNotEquals(AiOrchestratorRun::STATUS_RUNNING, $run->status,
            'Dead lease should have been reclaimed (not still RUNNING)');
    }

    /**
     * C5: A FAILED run should be reset to PENDING so it can be retried.
     */
    public function test_failed_run_is_reset_to_pending_for_retry(): void
    {
        $chat = Chat::factory()->create(['company_id' => TenantCompany::id()]);
        $trigger = Message::factory()->create([
            'chat_id' => $chat->id,
            'direction' => 'inbound',
        ]);

        $run = AiOrchestratorRun::create([
            'company_id' => TenantCompany::id(),
            'chat_id' => $chat->id,
            'trigger_message_id' => $trigger->id,
            'funnel_id' => null,
            'funnel_stage_id' => null,
            'status' => AiOrchestratorRun::STATUS_FAILED,
            'error' => 'Previous failure',
        ]);

        $orchestrator = app(AiFunnelOrchestratorService::class);
        $orchestrator->run($chat->id, $trigger->id);

        $run->refresh();
        // Should have been reset to PENDING (then attempt proceeded and likely
        // completed or failed differently — either way no longer FAILED from before).
        $this->assertNotEquals('Previous failure', $run->error,
            'Failed run should have been reset for retry');
    }
}
