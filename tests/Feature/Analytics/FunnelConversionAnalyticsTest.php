<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use App\Models\Chat;
use App\Models\ChatFunnelTransition;
use App\Models\Company;
use App\Models\Funnel;
use App\Models\FunnelStage;
use App\Models\Message;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\WhatsappSession;
use App\Support\TenantCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class FunnelConversionAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['administrator', 'manager', 'employee'] as $role) {
            Role::findOrCreate($role);
        }

        SystemSetting::setValue('module_funnels', 'on');
        TenantCompany::ensureExists();
    }

    public function test_funnel_analytics_returns_conversion_metrics(): void
    {
        $company = Company::query()->findOrFail(TenantCompany::id());
        $admin = User::factory()->create(['company_id' => $company->id]);
        $admin->assignRole('administrator');

        $session = WhatsappSession::factory()->create();

        $funnel = Funnel::query()->create([
            'company_id' => $company->id,
            'name' => 'Продажи',
            'description' => null,
            'color' => '#25d366',
            'is_active' => true,
            'position' => 0,
        ]);

        $stage1 = FunnelStage::query()->create([
            'funnel_id' => $funnel->id,
            'name' => 'Лид',
            'color' => '#3b82f6',
            'position' => 0,
            'is_active' => true,
        ]);
        $stage2 = FunnelStage::query()->create([
            'funnel_id' => $funnel->id,
            'name' => 'Замер',
            'color' => '#22c55e',
            'position' => 1,
            'is_active' => true,
        ]);

        $chat = Chat::factory()->create([
            'company_id' => $company->id,
            'whatsapp_session_id' => $session->id,
            'funnel_id' => $funnel->id,
            'funnel_stage_id' => $stage2->id,
            'is_group' => false,
        ]);

        Message::query()->create([
            'chat_id' => $chat->id,
            'whatsapp_session_id' => $session->id,
            'direction' => 'inbound',
            'type' => 'text',
            'body' => 'Привет',
            'message_timestamp' => now()->subDay(),
        ]);

        ChatFunnelTransition::query()->create([
            'chat_id' => $chat->id,
            'company_id' => $company->id,
            'from_funnel_id' => null,
            'from_stage_id' => null,
            'to_funnel_id' => $funnel->id,
            'to_stage_id' => $stage1->id,
            'source' => ChatFunnelTransition::SOURCE_MANUAL,
            'created_at' => now()->subDays(2),
        ]);

        ChatFunnelTransition::query()->create([
            'chat_id' => $chat->id,
            'company_id' => $company->id,
            'from_funnel_id' => $funnel->id,
            'from_stage_id' => $stage1->id,
            'to_funnel_id' => $funnel->id,
            'to_stage_id' => $stage2->id,
            'source' => ChatFunnelTransition::SOURCE_AI,
            'created_at' => now()->subDay(),
        ]);

        $from = now()->subDays(7)->format('Y-m-d');
        $to = now()->format('Y-m-d');

        $response = $this->actingAs($admin)->getJson('/api/analytics/funnels?'.http_build_query([
            'from' => $from,
            'to' => $to,
        ]));

        $response->assertOk();
        $response->assertJsonPath('conversion.summary.tracked_chats', 1);
        $response->assertJsonPath('conversion.funnels.0.stages.0.entries', 1);
        $response->assertJsonPath('conversion.funnels.0.stages.0.forward_exits', 1);
        $response->assertJsonPath('conversion.funnels.0.stages.0.conversion_percent', 100);
        $response->assertJsonPath('conversion.funnels.0.stages.1.current_chats', 1);
    }
}
