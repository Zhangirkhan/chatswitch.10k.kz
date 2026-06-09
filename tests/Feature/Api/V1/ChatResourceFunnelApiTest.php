<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Models\Chat;
use App\Models\Company;
use App\Models\Funnel;
use App\Models\FunnelStage;
use App\Models\Message;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\WhatsappSession;
use App\Support\TenantCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class ChatResourceFunnelApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['administrator', 'manager', 'employee'] as $roleName) {
            Role::findOrCreate($roleName);
        }
        SystemSetting::setValue('module_funnels', 'on');
        TenantCompany::ensureExists();
    }

    public function test_chat_show_includes_ai_and_funnel_progress(): void
    {
        $company = Company::query()->findOrFail(TenantCompany::id());
        $admin = User::factory()->create(['company_id' => $company->id]);
        $admin->assignRole('administrator');

        $funnel = Funnel::query()->create([
            'company_id' => $company->id,
            'name' => 'Продажи',
            'color' => '#01b964',
            'is_active' => true,
            'position' => 0,
        ]);
        $stage = FunnelStage::query()->create([
            'funnel_id' => $funnel->id,
            'name' => 'КП',
            'color' => '#3b82f6',
            'position' => 1,
            'is_active' => true,
        ]);

        $session = WhatsappSession::factory()->create();
        $chat = Chat::factory()->create([
            'whatsapp_session_id' => $session->id,
            'ai_enabled' => true,
            'ai_mode' => 'auto',
            'funnel_id' => $funnel->id,
            'funnel_stage_id' => $stage->id,
            'funnel_tracking_enabled' => true,
        ]);

        Sanctum::actingAs($admin);

        $this->getJson("/api/v1/chats/{$chat->id}")
            ->assertOk()
            ->assertJsonPath('data.ai_enabled', true)
            ->assertJsonPath('data.ai_mode', 'auto')
            ->assertJsonPath('data.funnel_id', $funnel->id)
            ->assertJsonPath('data.funnel.name', 'Продажи')
            ->assertJsonPath('data.funnel_stage.name', 'КП')
            ->assertJsonStructure([
                'data' => [
                    'funnel_progress' => ['percent', 'stage_index', 'stages_count'],
                    'funnel_progress_percent',
                ],
            ]);
    }

    public function test_chat_index_includes_funnel_stage_for_inbox_badge(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('administrator');
        Sanctum::actingAs($admin);

        $company = Company::query()->findOrFail(TenantCompany::id());
        $funnel = Funnel::query()->create([
            'company_id' => $company->id,
            'name' => 'Продажи',
            'color' => '#01b964',
            'is_active' => true,
            'position' => 0,
        ]);
        $stage = FunnelStage::query()->create([
            'funnel_id' => $funnel->id,
            'name' => 'Новый',
            'color' => '#94a3b8',
            'position' => 0,
            'is_active' => true,
        ]);

        $session = WhatsappSession::factory()->create();
        $chat = Chat::factory()->create([
            'whatsapp_session_id' => $session->id,
            'funnel_id' => $funnel->id,
            'funnel_stage_id' => $stage->id,
        ]);

        Message::query()->create([
            'chat_id' => $chat->id,
            'whatsapp_session_id' => $session->id,
            'direction' => 'inbound',
            'type' => 'chat',
            'body' => 'hello',
            'message_timestamp' => now(),
        ]);

        $this->getJson('/api/v1/chats')
            ->assertOk()
            ->assertJsonPath('data.0.funnel_stage_id', $stage->id)
            ->assertJsonPath('data.0.funnel_stage.name', 'Новый');
    }
}
