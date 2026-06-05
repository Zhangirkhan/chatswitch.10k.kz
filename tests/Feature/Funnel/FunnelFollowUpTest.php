<?php

declare(strict_types=1);

namespace Tests\Feature\Funnel;

use App\Models\Chat;
use App\Models\ChatAssignment;
use App\Models\Company;
use App\Models\Funnel;
use App\Models\FunnelStage;
use App\Models\FunnelStageAiRule;
use App\Models\Message;
use App\Models\ScheduledMessage;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\WhatsappSession;
use App\Services\Funnel\FunnelStageFollowUpService;
use App\Support\TenantCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class FunnelFollowUpTest extends TestCase
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

    public function test_command_schedules_follow_up_for_silent_client_chat(): void
    {
        $company = Company::query()->findOrFail(TenantCompany::id());
        $operator = User::factory()->create(['company_id' => $company->id]);
        $operator->assignRole('employee');

        $funnel = Funnel::query()->create([
            'company_id' => $company->id,
            'name' => 'Продажи',
            'description' => null,
            'color' => '#25d366',
            'is_active' => true,
            'position' => 0,
        ]);
        $stage = FunnelStage::query()->create([
            'funnel_id' => $funnel->id,
            'name' => 'Думает',
            'color' => '#3b82f6',
            'position' => 0,
            'is_active' => true,
        ]);

        FunnelStageAiRule::query()->create([
            'company_id' => $company->id,
            'funnel_id' => $funnel->id,
            'funnel_stage_id' => $stage->id,
            'goal' => 'Дожать клиента',
            'follow_up_enabled' => true,
            'follow_up_delay_hours' => 1,
            'follow_up_silence_after' => FunnelStageAiRule::FOLLOW_UP_SILENCE_OUTBOUND,
            'follow_up_message' => 'Здравствуйте, {chat_name}!',
            'follow_up_cooldown_hours' => 48,
            'follow_up_max_count' => 2,
        ]);

        $session = WhatsappSession::factory()->create();
        $chat = Chat::factory()->create([
            'company_id' => $company->id,
            'whatsapp_session_id' => $session->id,
            'funnel_id' => $funnel->id,
            'funnel_stage_id' => $stage->id,
            'funnel_tracking_enabled' => true,
            'chat_name' => 'Айгуль',
            'is_group' => false,
            'last_message_direction' => 'outbound',
            'last_message_at' => now()->subHours(3),
        ]);

        ChatAssignment::query()->create([
            'chat_id' => $chat->id,
            'user_id' => $operator->id,
            'assigned_by' => $operator->id,
        ]);

        Message::query()->create([
            'chat_id' => $chat->id,
            'whatsapp_session_id' => $session->id,
            'direction' => 'inbound',
            'type' => 'text',
            'body' => 'Подумаю',
            'message_timestamp' => now()->subHours(4),
        ]);

        Message::query()->create([
            'chat_id' => $chat->id,
            'whatsapp_session_id' => $session->id,
            'direction' => 'outbound',
            'type' => 'text',
            'body' => 'Хорошо, напишите, если будут вопросы.',
            'message_timestamp' => now()->subHours(3),
            'metadata' => ['ai' => ['generated' => true]],
        ]);

        $created = app(FunnelStageFollowUpService::class)->scheduleDue();

        $this->assertSame(1, $created);
        $this->assertDatabaseHas('scheduled_messages', [
            'chat_id' => $chat->id,
            'purpose' => ScheduledMessage::PURPOSE_FUNNEL_FOLLOW_UP,
            'funnel_stage_id' => $stage->id,
            'status' => ScheduledMessage::STATUS_PENDING,
        ]);

        $scheduled = ScheduledMessage::query()->where('chat_id', $chat->id)->first();
        $this->assertStringContainsString('Айгуль', (string) $scheduled?->body);
    }

    public function test_inbound_message_cancels_pending_follow_up(): void
    {
        $company = Company::query()->findOrFail(TenantCompany::id());
        $session = WhatsappSession::factory()->create();
        $chat = Chat::factory()->create([
            'company_id' => $company->id,
            'whatsapp_session_id' => $session->id,
            'is_group' => false,
        ]);

        ScheduledMessage::query()->create([
            'chat_id' => $chat->id,
            'whatsapp_session_id' => $session->id,
            'user_id' => User::factory()->create()->id,
            'purpose' => ScheduledMessage::PURPOSE_FUNNEL_FOLLOW_UP,
            'funnel_stage_id' => null,
            'body' => 'Напоминание',
            'display_body' => 'Напоминание',
            'scheduled_at' => now()->addHour(),
            'status' => ScheduledMessage::STATUS_PENDING,
        ]);

        app(FunnelStageFollowUpService::class)->cancelPendingForChat($chat);

        $this->assertDatabaseHas('scheduled_messages', [
            'chat_id' => $chat->id,
            'status' => ScheduledMessage::STATUS_CANCELLED,
        ]);
    }

    public function test_ab_follow_up_uses_variant_b_when_ratio_is_full(): void
    {
        $company = Company::query()->findOrFail(TenantCompany::id());
        $operator = User::factory()->create(['company_id' => $company->id]);
        $operator->assignRole('employee');

        $funnel = Funnel::query()->create([
            'company_id' => $company->id,
            'name' => 'Продажи',
            'description' => null,
            'color' => '#25d366',
            'is_active' => true,
            'position' => 0,
        ]);
        $stage = FunnelStage::query()->create([
            'funnel_id' => $funnel->id,
            'name' => 'Думает',
            'color' => '#3b82f6',
            'position' => 0,
            'is_active' => true,
        ]);

        FunnelStageAiRule::query()->create([
            'company_id' => $company->id,
            'funnel_id' => $funnel->id,
            'funnel_stage_id' => $stage->id,
            'goal' => 'Дожать клиента',
            'follow_up_enabled' => true,
            'follow_up_delay_hours' => 1,
            'follow_up_message' => 'Вариант A для {chat_name}',
            'follow_up_mode' => FunnelStageAiRule::FOLLOW_UP_MODE_AB,
            'follow_up_message_b' => 'Вариант B для {chat_name}',
            'follow_up_ab_ratio' => 100,
            'follow_up_cooldown_hours' => 48,
            'follow_up_max_count' => 2,
        ]);

        $session = WhatsappSession::factory()->create();
        $chat = Chat::factory()->create([
            'company_id' => $company->id,
            'whatsapp_session_id' => $session->id,
            'funnel_id' => $funnel->id,
            'funnel_stage_id' => $stage->id,
            'funnel_tracking_enabled' => true,
            'chat_name' => 'Тест',
            'is_group' => false,
            'last_message_direction' => 'outbound',
            'last_message_at' => now()->subHours(3),
        ]);

        ChatAssignment::query()->create([
            'chat_id' => $chat->id,
            'user_id' => $operator->id,
            'assigned_by' => $operator->id,
        ]);

        Message::query()->create([
            'chat_id' => $chat->id,
            'whatsapp_session_id' => $session->id,
            'direction' => 'inbound',
            'type' => 'text',
            'body' => 'Подумаю',
            'message_timestamp' => now()->subHours(4),
        ]);

        Message::query()->create([
            'chat_id' => $chat->id,
            'whatsapp_session_id' => $session->id,
            'direction' => 'outbound',
            'type' => 'text',
            'body' => 'Жду вашего решения.',
            'message_timestamp' => now()->subHours(3),
            'metadata' => ['ai' => ['generated' => true]],
        ]);

        app(FunnelStageFollowUpService::class)->scheduleDue();

        $scheduled = ScheduledMessage::query()->where('chat_id', $chat->id)->first();
        $this->assertStringContainsString('Вариант B', (string) $scheduled?->body);
        $this->assertStringNotContainsString('Вариант A', (string) $scheduled?->body);
    }
}
