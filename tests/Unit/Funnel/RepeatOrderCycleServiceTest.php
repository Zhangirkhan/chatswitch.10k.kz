<?php

declare(strict_types=1);

namespace Tests\Unit\Funnel;

use App\Models\Chat;
use App\Models\ChatFunnelTransition;
use App\Models\Funnel;
use App\Models\FunnelStage;
use App\Models\Message;
use App\Models\WhatsappSession;
use App\Services\AI\ChatSalesStateService;
use App\Services\Funnel\RepeatOrderCycleService;
use App\Support\TenantCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class RepeatOrderCycleServiceTest extends TestCase
{
    use RefreshDatabase;

    private RepeatOrderCycleService $service;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['administrator', 'manager', 'employee'] as $role) {
            Role::findOrCreate($role);
        }
        TenantCompany::ensureExists();
        $this->service = app(RepeatOrderCycleService::class);
    }

    public function test_restarts_funnel_from_closure_stage_on_repeat_order(): void
    {
        $session = WhatsappSession::factory()->create(['company_id' => TenantCompany::id()]);
        $funnel = Funnel::query()->create([
            'company_id' => TenantCompany::id(),
            'name' => 'Продажи',
            'color' => '#000',
            'is_active' => true,
            'position' => 0,
        ]);
        $qualStage = FunnelStage::query()->create([
            'funnel_id' => $funnel->id,
            'name' => 'Уточнение заказа',
            'color' => '#00f',
            'position' => 2,
            'is_active' => true,
        ]);
        $closureStage = FunnelStage::query()->create([
            'funnel_id' => $funnel->id,
            'name' => 'Закрытие заказа',
            'color' => '#0f0',
            'position' => 4,
            'is_active' => true,
        ]);

        $chat = Chat::factory()->create([
            'company_id' => TenantCompany::id(),
            'whatsapp_session_id' => $session->id,
            'funnel_id' => $funnel->id,
            'funnel_stage_id' => $closureStage->id,
            'funnel_tracking_enabled' => true,
            'is_lead_closed' => true,
            'sales_state' => ['qualified' => true, 'next_action' => 'confirm_delivery'],
        ]);

        $message = Message::query()->create([
            'chat_id' => $chat->id,
            'whatsapp_session_id' => $session->id,
            'whatsapp_message_id' => 'repeat-order-1',
            'direction' => 'inbound',
            'type' => 'chat',
            'body' => 'здравствуйте, хочу еще заказать помидоры',
            'message_timestamp' => now(),
        ]);

        $restarted = $this->service->restartIfNeeded($chat, $message);

        $this->assertTrue($restarted);
        $chat->refresh();
        $this->assertSame($qualStage->id, $chat->funnel_stage_id);
        $this->assertFalse($chat->is_lead_closed);
        $this->assertNull($chat->ai_orchestrator_status);
        $this->assertTrue($chat->sales_state['repeat_order_cycle'] ?? false);
        $this->assertSame(ChatSalesStateService::NA_ASK_REQUIREMENTS, $chat->sales_state['next_action'] ?? null);

        $transition = ChatFunnelTransition::query()
            ->where('chat_id', $chat->id)
            ->latest('id')
            ->first();
        $this->assertNotNull($transition);
        $this->assertSame(ChatFunnelTransition::SOURCE_SYSTEM, $transition->source);
        $this->assertSame($closureStage->id, $transition->from_stage_id);
        $this->assertSame($qualStage->id, $transition->to_stage_id);
    }

    public function test_does_not_restart_when_not_at_closure_stage(): void
    {
        $session = WhatsappSession::factory()->create(['company_id' => TenantCompany::id()]);
        $funnel = Funnel::query()->create([
            'company_id' => TenantCompany::id(),
            'name' => 'Продажи',
            'color' => '#000',
            'is_active' => true,
            'position' => 0,
        ]);
        $qualStage = FunnelStage::query()->create([
            'funnel_id' => $funnel->id,
            'name' => 'Уточнение заказа',
            'color' => '#00f',
            'position' => 2,
            'is_active' => true,
        ]);

        $chat = Chat::factory()->create([
            'company_id' => TenantCompany::id(),
            'whatsapp_session_id' => $session->id,
            'funnel_id' => $funnel->id,
            'funnel_stage_id' => $qualStage->id,
            'funnel_tracking_enabled' => true,
        ]);

        $message = Message::query()->create([
            'chat_id' => $chat->id,
            'whatsapp_session_id' => $session->id,
            'whatsapp_message_id' => 'repeat-order-2',
            'direction' => 'inbound',
            'type' => 'chat',
            'body' => 'хочу еще заказать помидоры',
            'message_timestamp' => now(),
        ]);

        $this->assertFalse($this->service->restartIfNeeded($chat, $message));
        $this->assertSame($qualStage->id, $chat->fresh()->funnel_stage_id);
    }
}
