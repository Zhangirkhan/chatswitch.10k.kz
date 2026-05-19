<?php

declare(strict_types=1);

namespace Tests\Unit\Funnel;

use App\Models\Chat;
use App\Models\ChatFunnelTransition;
use App\Models\Company;
use App\Models\Funnel;
use App\Models\FunnelStage;
use App\Models\Message;
use App\Models\WhatsappSession;
use App\Services\Funnel\FunnelStageResponseTimeAnalytics;
use App\Support\TenantCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class FunnelStageResponseTimeAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_pairs_inbound_with_ai_outbound_on_stage(): void
    {
        TenantCompany::ensureExists();
        $company = Company::query()->findOrFail(TenantCompany::id());
        $session = WhatsappSession::factory()->create();
        $funnel = Funnel::query()->create([
            'company_id' => $company->id,
            'name' => 'F',
            'color' => '#000',
            'is_active' => true,
            'position' => 0,
        ]);
        $stage = FunnelStage::query()->create([
            'funnel_id' => $funnel->id,
            'name' => 'Лид',
            'color' => '#000',
            'position' => 0,
            'is_active' => true,
        ]);
        $chat = Chat::factory()->create([
            'company_id' => $company->id,
            'whatsapp_session_id' => $session->id,
            'funnel_id' => $funnel->id,
            'funnel_stage_id' => $stage->id,
        ]);

        $from = now()->subDays(7)->startOfDay();
        $to = now()->endOfDay();
        $entered = now()->subDays(3);

        ChatFunnelTransition::query()->create([
            'chat_id' => $chat->id,
            'company_id' => $company->id,
            'to_funnel_id' => $funnel->id,
            'to_stage_id' => $stage->id,
            'source' => ChatFunnelTransition::SOURCE_MANUAL,
            'created_at' => $entered,
        ]);

        $inboundAt = $entered->copy()->addHour();
        Message::query()->create([
            'chat_id' => $chat->id,
            'whatsapp_session_id' => $session->id,
            'direction' => 'inbound',
            'type' => 'text',
            'body' => 'Цена?',
            'message_timestamp' => $inboundAt,
        ]);
        Message::query()->create([
            'chat_id' => $chat->id,
            'whatsapp_session_id' => $session->id,
            'direction' => 'outbound',
            'type' => 'text',
            'body' => 'AI ответ',
            'message_timestamp' => $inboundAt->copy()->addMinutes(20),
            'metadata' => ['ai' => ['generated' => true]],
        ]);

        $result = app(FunnelStageResponseTimeAnalytics::class)->avgMinutesByStage(
            [$chat->id],
            [$stage->id],
            $from,
            $to,
        );

        $this->assertSame(20.0, $result[$stage->id]['avg_response_minutes_ai']);
        $this->assertSame(1, $result[$stage->id]['response_samples_ai']);
    }
}
