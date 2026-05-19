<?php

declare(strict_types=1);

namespace Tests\Feature\Funnel;

use App\Models\Chat;
use App\Models\ChatAssignment;
use App\Models\ChatFunnelTransition;
use App\Models\Company;
use App\Models\Department;
use App\Models\Funnel;
use App\Models\FunnelStage;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\WhatsappSession;
use App\Support\TenantCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class ChatFunnelManualUpdateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['administrator', 'manager', 'employee'] as $r) {
            Role::findOrCreate($r);
        }
        SystemSetting::setValue('module_funnels', 'on');
        TenantCompany::ensureExists();
    }

    public function test_assigned_employee_can_update_funnel_stage_from_catalog(): void
    {
        $company = Company::query()->findOrFail(TenantCompany::id());
        $user = User::factory()->create(['company_id' => $company->id]);
        $user->assignRole('employee');

        $funnel = Funnel::query()->create([
            'company_id' => $company->id,
            'name' => 'Основная',
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
            'name' => 'КП',
            'color' => '#6366f1',
            'position' => 1,
            'is_active' => true,
        ]);

        $dept = Department::query()->create([
            'name' => 'Продажи',
            'description' => null,
            'is_active' => true,
        ]);
        $dept->funnels()->sync([$funnel->id]);
        $dept->funnelStages()->sync([$stage1->id, $stage2->id]);

        $session = WhatsappSession::factory()->create();
        $chat = Chat::factory()->create([
            'whatsapp_session_id' => $session->id,
            'company_id' => $company->id,
            'is_group' => false,
        ]);
        $chat->departments()->sync([$dept->id]);
        ChatAssignment::query()->create([
            'chat_id' => $chat->id,
            'user_id' => $user->id,
            'assigned_by' => $user->id,
        ]);

        $response = $this->actingAs($user)->patchJson(route('chats.funnel.update', $chat), [
            'funnel_id' => $funnel->id,
            'funnel_stage_id' => $stage2->id,
            'funnel_tracking_enabled' => true,
            'funnel_stage_locked' => false,
        ]);

        $response->assertOk()->assertJsonPath('success', true);

        $chat->refresh();
        $this->assertSame($funnel->id, $chat->funnel_id);
        $this->assertSame($stage2->id, $chat->funnel_stage_id);

        $this->assertDatabaseHas('chat_funnel_transitions', [
            'chat_id' => $chat->id,
            'to_funnel_id' => $funnel->id,
            'to_stage_id' => $stage2->id,
            'source' => ChatFunnelTransition::SOURCE_MANUAL,
        ]);
    }

    public function test_rejects_stage_not_in_department_catalog(): void
    {
        $company = Company::query()->findOrFail(TenantCompany::id());
        $user = User::factory()->create(['company_id' => $company->id]);
        $user->assignRole('employee');

        $funnelA = Funnel::query()->create([
            'company_id' => $company->id,
            'name' => 'A',
            'description' => null,
            'color' => '#25d366',
            'is_active' => true,
            'position' => 0,
        ]);
        $stageA = FunnelStage::query()->create([
            'funnel_id' => $funnelA->id,
            'name' => 'S',
            'color' => '#3b82f6',
            'position' => 0,
            'is_active' => true,
        ]);

        $funnelB = Funnel::query()->create([
            'company_id' => $company->id,
            'name' => 'B',
            'description' => null,
            'color' => '#111111',
            'is_active' => true,
            'position' => 1,
        ]);
        $stageB = FunnelStage::query()->create([
            'funnel_id' => $funnelB->id,
            'name' => 'Other',
            'color' => '#222222',
            'position' => 0,
            'is_active' => true,
        ]);

        $dept = Department::query()->create([
            'name' => 'Продажи',
            'description' => null,
            'is_active' => true,
        ]);
        $dept->funnels()->sync([$funnelA->id]);
        $dept->funnelStages()->sync([$stageA->id]);

        $session = WhatsappSession::factory()->create();
        $chat = Chat::factory()->create([
            'whatsapp_session_id' => $session->id,
            'company_id' => $company->id,
        ]);
        $chat->departments()->sync([$dept->id]);
        ChatAssignment::query()->create([
            'chat_id' => $chat->id,
            'user_id' => $user->id,
            'assigned_by' => $user->id,
        ]);

        $this->actingAs($user)->patchJson(route('chats.funnel.update', $chat), [
            'funnel_id' => $funnelB->id,
            'funnel_stage_id' => $stageB->id,
        ])->assertStatus(422);
    }

    public function test_switching_funnel_preserves_stage_index(): void
    {
        $company = Company::query()->findOrFail(TenantCompany::id());
        $user = User::factory()->create(['company_id' => $company->id]);
        $user->assignRole('employee');

        $funnelA = Funnel::query()->create([
            'company_id' => $company->id,
            'name' => 'Продажи',
            'description' => null,
            'color' => '#25d366',
            'is_active' => true,
            'position' => 0,
        ]);
        $stageA1 = FunnelStage::query()->create([
            'funnel_id' => $funnelA->id,
            'name' => 'Лид',
            'color' => '#3b82f6',
            'position' => 0,
            'is_active' => true,
        ]);
        $stageA2 = FunnelStage::query()->create([
            'funnel_id' => $funnelA->id,
            'name' => 'КП',
            'color' => '#6366f1',
            'position' => 1,
            'is_active' => true,
        ]);
        $stageA3 = FunnelStage::query()->create([
            'funnel_id' => $funnelA->id,
            'name' => 'Сделка',
            'color' => '#8b5cf6',
            'position' => 2,
            'is_active' => true,
        ]);

        $funnelB = Funnel::query()->create([
            'company_id' => $company->id,
            'name' => 'Сервис',
            'description' => null,
            'color' => '#111111',
            'is_active' => true,
            'position' => 1,
        ]);
        $stageB1 = FunnelStage::query()->create([
            'funnel_id' => $funnelB->id,
            'name' => 'Заявка',
            'color' => '#222222',
            'position' => 0,
            'is_active' => true,
        ]);
        $stageB2 = FunnelStage::query()->create([
            'funnel_id' => $funnelB->id,
            'name' => 'В работе',
            'color' => '#333333',
            'position' => 1,
            'is_active' => true,
        ]);

        $dept = Department::query()->create([
            'name' => 'Продажи',
            'description' => null,
            'is_active' => true,
        ]);
        $dept->funnels()->sync([$funnelA->id, $funnelB->id]);
        $dept->funnelStages()->sync([
            $stageA1->id,
            $stageA2->id,
            $stageA3->id,
            $stageB1->id,
            $stageB2->id,
        ]);

        $session = WhatsappSession::factory()->create();
        $chat = Chat::factory()->create([
            'whatsapp_session_id' => $session->id,
            'company_id' => $company->id,
            'funnel_id' => $funnelA->id,
            'funnel_stage_id' => $stageA3->id,
        ]);
        $chat->departments()->sync([$dept->id]);
        ChatAssignment::query()->create([
            'chat_id' => $chat->id,
            'user_id' => $user->id,
            'assigned_by' => $user->id,
        ]);

        $this->actingAs($user)->patchJson(route('chats.funnel.update', $chat), [
            'funnel_id' => $funnelB->id,
            'funnel_stage_id' => $stageA3->id,
        ])->assertOk()->assertJsonPath('chat.funnel_stage_id', $stageB2->id);

        $chat->refresh();
        $this->assertSame($funnelB->id, $chat->funnel_id);
        $this->assertSame($stageB2->id, $chat->funnel_stage_id);
    }
}
