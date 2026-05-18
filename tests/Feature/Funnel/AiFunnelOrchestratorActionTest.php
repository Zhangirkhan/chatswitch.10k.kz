<?php

declare(strict_types=1);

namespace Tests\Feature\Funnel;

use App\Jobs\SendOutboundMessageJob;
use App\Models\AiOrchestratorRun;
use App\Models\CalendarEvent;
use App\Models\Chat;
use App\Models\ChatAssignment;
use App\Models\ChatFunnelTransition;
use App\Models\Company;
use App\Models\Department;
use App\Models\DepartmentPost;
use App\Models\Funnel;
use App\Models\FunnelAiScenario;
use App\Models\FunnelStage;
use App\Models\FunnelStageAiRule;
use App\Models\Message;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\WhatsappSession;
use App\Services\AI\AiFunnelActionExecutor;
use App\Services\AI\AiFunnelOrchestratorPlan;
use App\Support\TenantCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class AiFunnelOrchestratorActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['administrator', 'manager', 'employee'] as $role) {
            Role::findOrCreate($role);
        }
        SystemSetting::setValue('module_funnels', 'on');
        SystemSetting::setValue('module_calendar', 'on');
        TenantCompany::ensureExists();
    }

    public function test_executor_books_assigns_replies_and_moves_stage(): void
    {
        Bus::fake([SendOutboundMessageJob::class]);

        [$company, $actor, $assignee, $department, $funnel, $stageOne, $stageTwo, $chat, $trigger] = $this->fixture();
        $scenario = FunnelAiScenario::query()->create([
            'company_id' => $company->id,
            'funnel_id' => $funnel->id,
            'enabled' => true,
            'booking_horizon_days' => 30,
            'fallback_manager_user_id' => $actor->id,
            'fallback_department_id' => $department->id,
        ]);
        $rule = FunnelStageAiRule::query()->create([
            'company_id' => $company->id,
            'funnel_id' => $funnel->id,
            'funnel_stage_id' => $stageOne->id,
            'allowed_actions' => FunnelStageAiRule::DEFAULT_ALLOWED_ACTIONS,
            'assignee_user_ids' => [$assignee->id],
        ]);
        $run = AiOrchestratorRun::query()->create([
            'company_id' => $company->id,
            'chat_id' => $chat->id,
            'trigger_message_id' => $trigger->id,
            'funnel_id' => $funnel->id,
            'funnel_stage_id' => $stageOne->id,
        ]);
        $startsAt = now()->addWeek()->setTime(10, 0);

        app(AiFunnelActionExecutor::class)->execute(
            $run,
            $chat,
            $trigger,
            $actor,
            $scenario->fresh(['fallbackDepartment']),
            $rule,
            new AiFunnelOrchestratorPlan(
                customerReply: 'Записали вас на замер, замерщик подключится к чату.',
                targetFunnelStageId: $stageTwo->id,
                appointment: [
                    'service_name' => 'Замер мебели',
                    'starts_at' => $startsAt->toIso8601String(),
                    'duration_minutes' => 60,
                    'client_note' => 'Адрес уточнён в переписке',
                ],
                assigneeUserId: $assignee->id,
                managerNote: null,
                task: null,
                requiresManagerAttention: false,
                confidence: 0.92,
                reason: 'Клиент согласовал замер.',
            ),
        );

        $this->assertDatabaseHas('messages', [
            'chat_id' => $chat->id,
            'direction' => 'outbound',
        ]);
        $this->assertDatabaseHas('calendar_events', [
            'chat_id' => $chat->id,
            'assignee_user_id' => $assignee->id,
            'source' => CalendarEvent::SOURCE_AI_AUTO,
        ]);
        $this->assertDatabaseHas('chat_assignments', [
            'chat_id' => $chat->id,
            'user_id' => $assignee->id,
        ]);
        $this->assertDatabaseHas('chat_funnel_transitions', [
            'chat_id' => $chat->id,
            'to_stage_id' => $stageTwo->id,
            'source' => ChatFunnelTransition::SOURCE_AI,
        ]);
        $this->assertDatabaseHas('ai_orchestrator_actions', [
            'ai_orchestrator_run_id' => $run->id,
            'type' => FunnelStageAiRule::ACTION_CREATE_APPOINTMENT,
            'status' => 'done',
        ]);
    }

    public function test_executor_creates_manager_note_and_task_for_attention(): void
    {
        [$company, $actor, $assignee, $department, $funnel, $stageOne, , $chat, $trigger] = $this->fixture();
        $scenario = FunnelAiScenario::query()->create([
            'company_id' => $company->id,
            'funnel_id' => $funnel->id,
            'enabled' => true,
            'booking_horizon_days' => 30,
            'fallback_manager_user_id' => $actor->id,
            'fallback_department_id' => $department->id,
        ])->fresh(['fallbackDepartment']);
        $rule = FunnelStageAiRule::query()->create([
            'company_id' => $company->id,
            'funnel_id' => $funnel->id,
            'funnel_stage_id' => $stageOne->id,
            'allowed_actions' => [FunnelStageAiRule::ACTION_NOTIFY_MANAGER, FunnelStageAiRule::ACTION_CREATE_TASK],
            'assignee_user_ids' => [$assignee->id],
        ]);
        $run = AiOrchestratorRun::query()->create([
            'company_id' => $company->id,
            'chat_id' => $chat->id,
            'trigger_message_id' => $trigger->id,
            'funnel_id' => $funnel->id,
            'funnel_stage_id' => $stageOne->id,
        ]);

        app(AiFunnelActionExecutor::class)->execute(
            $run,
            $chat,
            $trigger,
            $actor,
            $scenario,
            $rule,
            new AiFunnelOrchestratorPlan(
                customerReply: null,
                targetFunnelStageId: null,
                appointment: null,
                assigneeUserId: null,
                managerNote: 'Клиент просит исключение из правил записи.',
                task: [
                    'title' => 'Проверить нестандартную запись',
                    'body' => 'Клиент просит дату вне обычного горизонта.',
                ],
                requiresManagerAttention: true,
                confidence: 0.55,
                reason: 'Нужна проверка менеджера.',
            ),
        );

        $this->assertDatabaseHas('messages', [
            'chat_id' => $chat->id,
            'direction' => 'system',
        ]);
        $this->assertDatabaseHas('department_posts', [
            'department_id' => $department->id,
            'title' => 'Проверить нестандартную запись',
            'status' => DepartmentPost::STATUS_OPEN,
        ]);
        $this->assertDatabaseHas('team_messages', [
            'sender_id' => $actor->id,
        ]);
    }

    /**
     * @return array{Company, User, User, Department, Funnel, FunnelStage, FunnelStage, Chat, Message}
     */
    private function fixture(): array
    {
        $company = Company::query()->findOrFail(TenantCompany::id());
        $actor = User::factory()->create(['company_id' => $company->id]);
        $actor->assignRole('manager');
        $assignee = User::factory()->create(['company_id' => $company->id]);
        $assignee->assignRole('employee');
        $department = Department::query()->create(['name' => 'Замерщики', 'is_active' => true]);
        $department->users()->sync([$actor->id, $assignee->id]);

        $funnel = Funnel::query()->create([
            'company_id' => $company->id,
            'name' => 'Мебель',
            'color' => '#25d366',
            'is_active' => true,
            'position' => 0,
        ]);
        $stageOne = FunnelStage::query()->create([
            'funnel_id' => $funnel->id,
            'name' => 'Запись на замер',
            'color' => '#3b82f6',
            'position' => 0,
            'is_active' => true,
        ]);
        $stageTwo = FunnelStage::query()->create([
            'funnel_id' => $funnel->id,
            'name' => 'Замер назначен',
            'color' => '#22c55e',
            'position' => 1,
            'is_active' => true,
        ]);

        $session = WhatsappSession::factory()->create();
        $chat = Chat::factory()->create([
            'whatsapp_session_id' => $session->id,
            'company_id' => $company->id,
            'is_group' => false,
            'funnel_id' => $funnel->id,
            'funnel_stage_id' => $stageOne->id,
        ]);
        ChatAssignment::query()->create([
            'chat_id' => $chat->id,
            'user_id' => $actor->id,
            'assigned_by' => $actor->id,
        ]);
        $trigger = Message::query()->create([
            'chat_id' => $chat->id,
            'whatsapp_session_id' => $session->id,
            'whatsapp_message_id' => 'inbound-test',
            'direction' => 'inbound',
            'type' => 'chat',
            'body' => 'Хочу записаться на замер',
            'ack' => 'read',
            'message_timestamp' => now(),
        ]);

        return [$company, $actor, $assignee, $department, $funnel, $stageOne, $stageTwo, $chat, $trigger];
    }
}
