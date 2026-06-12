<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\Models\AiOrchestratorRun;
use App\Models\Chat;
use App\Models\Funnel;
use App\Models\FunnelAiScenario;
use App\Models\FunnelStage;
use App\Models\Message;
use App\Models\User;
use App\Services\AI\AiFunnelOrchestratorService;
use App\Support\TenantCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
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
        config([
            'ai.orchestrator_lease_timeout_minutes' => 5,
            'services.openai.api_key' => 'test-key',
        ]);
    }

    /**
     * C5: A RUNNING run that has been stuck longer than the lease timeout should be
     * reclaimed so the orchestrator can retry.
     */
    public function test_expired_running_lease_is_reclaimed(): void
    {
        [$chat, $trigger] = $this->createOrchestratorChatFixture();
        $staleStartedAt = now()->subMinutes(10);

        $run = AiOrchestratorRun::create([
            'company_id' => TenantCompany::id(),
            'chat_id' => $chat->id,
            'trigger_message_id' => $trigger->id,
            'funnel_id' => $chat->funnel_id,
            'funnel_stage_id' => $chat->funnel_stage_id,
            'status' => AiOrchestratorRun::STATUS_RUNNING,
            'started_at' => $staleStartedAt,
        ]);

        Http::fake([
            'https://api.openai.com/*' => Http::response([
                'choices' => [
                    ['message' => ['content' => json_encode([
                        'customerReply' => 'Здравствуйте!',
                        'targetFunnelStageId' => null,
                        'confidence' => 0.8,
                        'reason' => 'test',
                    ], JSON_THROW_ON_ERROR)]],
                ],
            ], 200),
        ]);

        app(AiFunnelOrchestratorService::class)->run($chat->id, $trigger->id);

        $run->refresh();
        $this->assertFalse(
            $run->status === AiOrchestratorRun::STATUS_RUNNING
            && $run->started_at?->equalTo($staleStartedAt),
            'Dead lease should have been reclaimed (not still RUNNING with stale started_at)',
        );
    }

    /**
     * C5: A FAILED run should be reset to PENDING so it can be retried.
     */
    public function test_failed_run_is_reset_to_pending_for_retry(): void
    {
        [$chat, $trigger] = $this->createOrchestratorChatFixture();

        $run = AiOrchestratorRun::create([
            'company_id' => TenantCompany::id(),
            'chat_id' => $chat->id,
            'trigger_message_id' => $trigger->id,
            'funnel_id' => $chat->funnel_id,
            'funnel_stage_id' => $chat->funnel_stage_id,
            'status' => AiOrchestratorRun::STATUS_FAILED,
            'error' => 'Previous failure',
        ]);

        Http::fake([
            'https://api.openai.com/*' => Http::response([
                'choices' => [
                    ['message' => ['content' => json_encode([
                        'customerReply' => 'Здравствуйте!',
                        'targetFunnelStageId' => null,
                        'confidence' => 0.8,
                        'reason' => 'test',
                    ], JSON_THROW_ON_ERROR)]],
                ],
            ], 200),
        ]);

        app(AiFunnelOrchestratorService::class)->run($chat->id, $trigger->id);

        $run->refresh();
        $this->assertNotEquals('Previous failure', $run->error,
            'Failed run should have been reset for retry');
    }

    /**
     * @return array{0: Chat, 1: Message}
     */
    private function createOrchestratorChatFixture(): array
    {
        $manager = User::factory()->create(['company_id' => TenantCompany::id()]);
        $manager->assignRole('manager');

        $funnel = Funnel::factory()->create(['company_id' => TenantCompany::id()]);
        $stage = FunnelStage::factory()->create([
            'funnel_id' => $funnel->id,
            'position' => 0,
        ]);

        FunnelAiScenario::query()->create([
            'company_id' => TenantCompany::id(),
            'funnel_id' => $funnel->id,
            'enabled' => true,
            'booking_horizon_days' => 14,
            'fallback_manager_user_id' => $manager->id,
        ]);

        $chat = Chat::factory()->create([
            'company_id' => TenantCompany::id(),
            'funnel_id' => $funnel->id,
            'funnel_stage_id' => $stage->id,
            'ai_enabled' => true,
            'ai_responder_user_id' => $manager->id,
        ]);

        $trigger = Message::factory()->create([
            'chat_id' => $chat->id,
            'direction' => 'inbound',
            'body' => 'Здравствуйте',
        ]);

        return [$chat, $trigger];
    }
}
