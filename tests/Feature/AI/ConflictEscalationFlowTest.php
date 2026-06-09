<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\Models\Chat;
use App\Models\Message;
use App\Models\User;
use App\Services\AI\ChatConflictService;
use App\Services\AI\InboundAiDispatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class ConflictEscalationFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['administrator', 'manager', 'employee'] as $roleName) {
            Role::findOrCreate($roleName);
        }

        config([
            'accel.conflict_handling.enabled' => true,
            'accel.conflict_handling.deescalation_max_attempts' => 2,
            'accel.conflict_handling.tier2_max_attempts' => 1,
            'accel.conflict_handling.tier3_max_attempts' => 0,
        ]);
    }

    public function test_tier1_escalates_after_two_deescalation_attempts(): void
    {
        $user = User::factory()->create();
        $user->assignRole('administrator');

        $chat = Chat::factory()->create([
            'ai_enabled' => true,
            'company_id' => $user->company_id,
        ]);

        $service = app(ChatConflictService::class);

        $first = Message::query()->create([
            'chat_id' => $chat->id,
            'company_id' => $chat->company_id,
            'whatsapp_session_id' => $chat->whatsapp_session_id,
            'direction' => 'inbound',
            'type' => 'text',
            'body' => 'Где мой заказ?! Обещали в пятницу',
            'message_timestamp' => now(),
        ]);

        $r1 = $service->resolveForInbound($chat->fresh(), $first, $user);
        $this->assertNotNull($r1);
        $this->assertFalse($r1['escalate']);
        $chat->refresh();
        $this->assertSame(1, (int) $chat->conflict_deescalation_count);

        $second = Message::query()->create([
            'chat_id' => $chat->id,
            'company_id' => $chat->company_id,
            'whatsapp_session_id' => $chat->whatsapp_session_id,
            'direction' => 'inbound',
            'type' => 'text',
            'body' => 'Уже 4 день жду!!!',
            'message_timestamp' => now()->addSecond(),
        ]);

        $r2 = $service->resolveForInbound($chat->fresh(), $second, $user);
        $this->assertNotNull($r2);
        $this->assertTrue($r2['escalate']);
        $chat->refresh();
        $this->assertSame('escalated', $chat->conflict_state);
        $this->assertNotNull($chat->ai_paused_at);
    }

    public function test_inbound_dispatch_skips_when_conflict_escalated(): void
    {
        Bus::fake();

        $chat = Chat::factory()->create([
            'ai_enabled' => true,
            'conflict_state' => 'escalated',
            'ai_paused_at' => now(),
        ]);

        $message = Message::query()->create([
            'chat_id' => $chat->id,
            'company_id' => $chat->company_id,
            'whatsapp_session_id' => $chat->whatsapp_session_id,
            'direction' => 'inbound',
            'type' => 'text',
            'body' => 'Ещё одно сообщение',
            'message_timestamp' => now(),
        ]);

        app(InboundAiDispatchService::class)->dispatchForInboundMessage($message);

        Bus::assertNothingDispatched();
    }

    public function test_clear_conflict_resumes_ai_tracking(): void
    {
        $chat = Chat::factory()->create([
            'conflict_state' => 'escalated',
            'ai_paused_at' => now(),
            'conflict_deescalation_count' => 2,
            'conflict_situation' => 'delay',
        ]);

        app(ChatConflictService::class)->clearConflict($chat->fresh());
        $chat->refresh();

        $this->assertSame('none', $chat->conflict_state);
        $this->assertNull($chat->ai_paused_at);
        $this->assertSame(0, (int) $chat->conflict_deescalation_count);
    }
}
