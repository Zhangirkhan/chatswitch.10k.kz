<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\Jobs\GenerateAiReplyJob;
use App\Jobs\ProcessWhatsappInboundJob;
use App\Models\Chat;
use App\Models\Company;
use App\Models\Department;
use App\Models\Funnel;
use App\Models\FunnelStage;
use App\Models\Message;
use App\Models\User;
use App\Models\WhatsappSession;
use App\Services\AI\ChatDepartmentClassifierService;
use App\Services\AI\ChatDepartmentRoutingService;
use App\Services\AI\ChatOffHoursReplyService;
use App\Support\DepartmentWorkSchedule;
use App\Tenancy\TenantContext;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class ChatDepartmentRoutingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('employee');
        Role::findOrCreate('manager');
        Role::findOrCreate('administrator');
    }

    public function test_routes_department_when_chat_has_none(): void
    {
        config()->set('services.openai.api_key', 'test-key');

        $sales = Department::query()->create(['name' => 'Отдел продаж', 'is_active' => true]);
        $operations = Department::query()->create(['name' => 'Замерщики', 'is_active' => true]);

        $session = WhatsappSession::factory()->create();
        $chat = Chat::factory()->create([
            'whatsapp_session_id' => $session->id,
            'ai_enabled' => true,
        ]);

        $trigger = Message::create([
            'chat_id' => $chat->id,
            'whatsapp_session_id' => $session->id,
            'direction' => 'inbound',
            'type' => 'chat',
            'body' => 'Здравствуйте, нужен замер',
            'ack' => 'delivered',
            'message_timestamp' => now(),
        ]);

        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [
                    ['message' => ['content' => json_encode([
                        'department_id' => $operations->id,
                        'confidence' => 0.88,
                        'should_assign' => true,
                        'reason' => 'Клиент просит замер.',
                    ], JSON_THROW_ON_ERROR)]],
                ],
            ]),
        ]);

        $routed = $this->app->make(ChatDepartmentRoutingService::class)->routeIfNeeded($chat, $trigger);

        $this->assertTrue($routed);
        $this->assertDatabaseHas('chat_department', [
            'chat_id' => $chat->id,
            'department_id' => $operations->id,
        ]);
        $this->assertDatabaseMissing('chat_department', [
            'chat_id' => $chat->id,
            'department_id' => $sales->id,
        ]);
    }

    public function test_auto_reply_works_without_manual_assignment(): void
    {
        config()->set('services.openai.api_key', 'test-key');
        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::sequence()
                ->push([
                    'choices' => [
                        ['message' => ['content' => 'Здравствуйте! Подскажите, чем можем помочь?']],
                    ],
                ]),
        ]);

        $company = $this->createTenantCompany(['name' => 'Company', 'slug' => 'company-auto-reply']);
        $manager = User::factory()->create(['company_id' => $company->id, 'is_active' => true]);
        $manager->assignRole('manager');

        $department = Department::query()->create(['name' => 'Продажи', 'is_active' => true]);
        $department->users()->sync([$manager->id]);

        $session = WhatsappSession::factory()->create();
        $chat = Chat::factory()->create([
            'company_id' => $company->id,
            'whatsapp_session_id' => $session->id,
            'ai_enabled' => true,
            'ai_mode' => 'auto',
        ]);
        $chat->departments()->sync([$department->id]);

        $trigger = Message::create([
            'chat_id' => $chat->id,
            'whatsapp_session_id' => $session->id,
            'direction' => 'inbound',
            'type' => 'chat',
            'body' => 'Добрый день',
            'ack' => 'delivered',
            'message_timestamp' => now(),
        ]);

        $job = new GenerateAiReplyJob($chat->id, $trigger->id);
        $this->app->call([$job, 'handle']);

        $this->assertDatabaseHas('ai_response_logs', [
            'chat_id' => $chat->id,
            'trigger_message_id' => $trigger->id,
            'user_id' => $manager->id,
            'status' => 'sent',
        ]);

        $outbound = Message::query()
            ->where('chat_id', $chat->id)
            ->where('direction', 'outbound')
            ->latest('id')
            ->first();

        $this->assertNotNull($outbound);
        $this->assertStringContainsString('Здравствуйте', (string) $outbound->body);
        $this->assertStringContainsString('помочь', mb_strtolower((string) $outbound->body));
        $this->assertStringNotContainsString('*'.$manager->name.'*', (string) $outbound->body);
    }

    public function test_inbound_job_routes_department_before_ai(): void
    {
        Queue::fake();

        $company = $this->createTenantCompany(['name' => 'Co']);
        $department = Department::query()->create(['name' => 'Продажи', 'is_active' => true]);
        $funnel = Funnel::query()->create([
            'company_id' => $company->id,
            'name' => 'Продажи',
            'color' => '#000',
            'position' => 0,
            'is_active' => true,
        ]);
        FunnelStage::query()->create([
            'funnel_id' => $funnel->id,
            'name' => 'Новый',
            'color' => '#111',
            'position' => 0,
            'stage_type' => 'open',
            'is_active' => true,
        ]);
        $department->funnels()->sync([$funnel->id]);

        $session = WhatsappSession::factory()->create();
        $chat = Chat::factory()->create([
            'company_id' => $company->id,
            'whatsapp_session_id' => $session->id,
            'ai_enabled' => true,
            'funnel_tracking_enabled' => true,
        ]);

        config()->set('services.openai.api_key', 'test-key');
        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [
                    ['message' => ['content' => json_encode([
                        'department_id' => $department->id,
                        'confidence' => 0.91,
                        'should_assign' => true,
                        'reason' => 'Первичное обращение.',
                    ], JSON_THROW_ON_ERROR)]],
                ],
            ]),
        ]);

        $job = new ProcessWhatsappInboundJob([
            'session' => $session->session_name,
            'chatId' => $chat->whatsapp_chat_id,
            'chatName' => $chat->chat_name,
            'from' => '77001112233',
            'body' => 'Привет',
            'type' => 'chat',
            'messageId' => 'msg-route-1',
            'timestamp' => now()->getTimestamp(),
            'isGroup' => false,
        ]);
        $this->app->call([$job, 'handle']);

        $chat->refresh();
        $this->assertTrue($chat->departments()->where('departments.id', $department->id)->exists());
        $this->assertSame($funnel->id, (int) $chat->funnel_id);
    }

    public function test_accountant_request_routes_to_accounting_not_first_department(): void
    {
        Department::query()->create(['name' => 'HR-отдел', 'is_active' => true]);
        $accounting = Department::query()->create([
            'name' => 'Бухгалтерия',
            'description' => 'Счета, оплата, акты',
            'is_active' => true,
        ]);

        $session = WhatsappSession::factory()->create();
        $chat = Chat::factory()->create([
            'whatsapp_session_id' => $session->id,
            'ai_enabled' => true,
        ]);

        $trigger = Message::create([
            'chat_id' => $chat->id,
            'whatsapp_session_id' => $session->id,
            'direction' => 'inbound',
            'type' => 'chat',
            'body' => 'здравствуйте, свяжите с бухгалтером',
            'ack' => 'delivered',
            'message_timestamp' => now(),
        ]);

        $classification = $this->app->make(ChatDepartmentClassifierService::class)->classify($chat, $trigger);

        $this->assertNotNull($classification);
        $this->assertSame($accounting->id, $classification->departmentId);
        $this->assertStringNotContainsString('первый активный', mb_strtolower($classification->reason));

        $department = $this->app->make(ChatDepartmentRoutingService::class)
            ->resolveAndAssignDepartment($chat, $trigger);

        $this->assertSame($accounting->id, (int) $department?->id);
    }

    public function test_accountant_request_gets_accounting_off_hours_not_hr(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-17 20:00:00', 'Asia/Almaty'));

        $company = $this->createTenantCompany(['name' => 'Company']);
        $manager = User::factory()->create(['company_id' => $company->id, 'is_active' => true]);
        $manager->assignRole('manager');

        Department::query()->create([
            'name' => 'HR-отдел',
            'is_active' => true,
            'work_schedule_enabled' => false,
        ]);
        $accounting = Department::query()->create([
            'name' => 'Бухгалтерия',
            'description' => 'Счета и оплата',
            'is_active' => true,
            'work_schedule_enabled' => true,
            'work_schedule_timezone' => 'Asia/Almaty',
            'work_schedule' => DepartmentWorkSchedule::defaultWeek(),
        ]);
        $accounting->users()->sync([$manager->id]);

        $session = WhatsappSession::factory()->create();
        $chat = Chat::factory()->create([
            'company_id' => $company->id,
            'whatsapp_session_id' => $session->id,
            'ai_enabled' => true,
            'ai_mode' => 'auto',
        ]);

        $trigger = Message::create([
            'chat_id' => $chat->id,
            'whatsapp_session_id' => $session->id,
            'direction' => 'inbound',
            'type' => 'chat',
            'body' => 'здравствуйте, свяжите с бухгалтером',
            'ack' => 'delivered',
            'message_timestamp' => now(),
        ]);

        $department = $this->app->make(ChatDepartmentRoutingService::class)
            ->resolveAndAssignDepartment($chat, $trigger);

        $this->assertSame($accounting->id, (int) $department?->id);

        $sent = $this->app->make(ChatOffHoursReplyService::class)->tryReply($chat, $trigger, $department);
        $this->assertTrue($sent);

        $outbound = Message::query()
            ->where('chat_id', $chat->id)
            ->where('direction', 'outbound')
            ->latest('id')
            ->first();

        $this->assertStringContainsString('Бухгалтерия', (string) $outbound?->body);
        $this->assertStringNotContainsString('HR', (string) $outbound?->body);

        Carbon::setTestNow();
    }
}
