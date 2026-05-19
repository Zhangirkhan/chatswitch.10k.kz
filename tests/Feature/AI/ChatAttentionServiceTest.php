<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\Models\AiOrchestratorRun;
use App\Models\Chat;
use App\Models\Company;
use App\Models\WhatsappSession;
use App\Services\AI\ChatAttentionService;
use App\Support\TenantCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ChatAttentionServiceTest extends TestCase
{
    use RefreshDatabase;

    private ChatAttentionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        TenantCompany::ensureExists();
        $this->service = app(ChatAttentionService::class);
    }

    public function test_includes_chat_with_recent_low_confidence_completed_run(): void
    {
        $company = Company::query()->firstOrFail();
        $session = WhatsappSession::factory()->create();
        $chat = Chat::factory()->create([
            'company_id' => $company->id,
            'whatsapp_session_id' => $session->id,
            'is_group' => false,
            'is_archived' => false,
            'unread_count' => 0,
            'last_message_direction' => 'outbound',
            'last_message_at' => now(),
            'ai_orchestrator_status' => AiOrchestratorRun::STATUS_COMPLETED,
        ]);

        $run = AiOrchestratorRun::query()->create([
            'company_id' => $company->id,
            'chat_id' => $chat->id,
            'status' => AiOrchestratorRun::STATUS_COMPLETED,
            'confidence' => 0.62,
            'reason' => 'Неясно, готов ли клиент к замеру.',
            'completed_at' => now()->subHour(),
        ]);

        $chat->forceFill(['ai_orchestrator_last_run_id' => $run->id])->save();
        $chat->load('lastOrchestratorRun');

        $this->assertTrue(
            $this->service->applyAttentionScope(Chat::query()->whereKey($chat->id))->exists(),
        );

        $meta = $this->service->describe($chat);
        $this->assertSame('warning', $meta['severity']);
        $this->assertStringContainsString('AI не уверен', $meta['reason']);
        $this->assertStringContainsString('62%', $meta['reason']);
    }

    public function test_excludes_chat_when_confidence_above_attention_threshold(): void
    {
        $company = Company::query()->firstOrFail();
        $session = WhatsappSession::factory()->create();
        $chat = Chat::factory()->create([
            'company_id' => $company->id,
            'whatsapp_session_id' => $session->id,
            'is_group' => false,
            'is_archived' => false,
            'unread_count' => 0,
            'last_message_direction' => 'outbound',
            'last_message_at' => now(),
            'ai_orchestrator_status' => AiOrchestratorRun::STATUS_COMPLETED,
        ]);

        $run = AiOrchestratorRun::query()->create([
            'company_id' => $company->id,
            'chat_id' => $chat->id,
            'status' => AiOrchestratorRun::STATUS_COMPLETED,
            'confidence' => 0.92,
            'completed_at' => now()->subHour(),
        ]);

        $chat->forceFill(['ai_orchestrator_last_run_id' => $run->id])->save();

        $this->assertFalse(
            $this->service->applyAttentionScope(Chat::query()->whereKey($chat->id))->exists(),
        );
    }
}
