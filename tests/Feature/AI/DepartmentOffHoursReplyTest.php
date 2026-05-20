<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\Jobs\ProcessWhatsappInboundJob;
use App\Models\Chat;
use App\Models\Company;
use App\Models\Department;
use App\Models\Message;
use App\Models\User;
use App\Models\WhatsappSession;
use App\Services\AI\ChatOffHoursReplyService;
use App\Support\DepartmentWorkSchedule;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class DepartmentOffHoursReplyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('manager');
        Role::findOrCreate('administrator');
    }

    public function test_off_hours_reply_sent_outside_schedule(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-17 20:00:00', 'Asia/Almaty')); // Saturday evening

        $company = Company::create(['name' => 'Company']);
        $manager = User::factory()->create(['company_id' => $company->id, 'is_active' => true]);
        $manager->assignRole('manager');

        $department = Department::query()->create([
            'name' => 'Продажи',
            'is_active' => true,
            'work_schedule_enabled' => true,
            'work_schedule_timezone' => 'Asia/Almaty',
            'work_schedule' => DepartmentWorkSchedule::defaultWeek(),
        ]);
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
            'body' => 'Здравствуйте',
            'ack' => 'delivered',
            'message_timestamp' => now(),
        ]);

        $sent = $this->app->make(ChatOffHoursReplyService::class)->tryReply($chat, $trigger, $department);

        $this->assertTrue($sent);
        $outbound = Message::query()
            ->where('chat_id', $chat->id)
            ->where('direction', 'outbound')
            ->latest('id')
            ->first();

        $this->assertNotNull($outbound);
        $this->assertStringContainsString('нерабочее время', mb_strtolower((string) $outbound->body));
        $this->assertStringContainsString('определили отдел', mb_strtolower((string) $outbound->body));
        $this->assertStringContainsString('продажи', mb_strtolower((string) $outbound->body));
        $this->assertStringContainsString('получено', mb_strtolower((string) $outbound->body));

        Carbon::setTestNow();
    }

    public function test_off_hours_uses_department_determined_for_message_not_stale_attachment(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-17 20:00:00', 'Asia/Almaty'));
        config()->set('services.openai.api_key', 'test-key');

        $company = Company::create(['name' => 'Company']);
        $manager = User::factory()->create(['company_id' => $company->id, 'is_active' => true]);
        $manager->assignRole('manager');

        $sales = Department::query()->create([
            'name' => 'Продажи',
            'is_active' => true,
            'work_schedule_enabled' => true,
            'work_schedule_timezone' => 'Asia/Almaty',
            'work_schedule' => DepartmentWorkSchedule::defaultWeek(),
        ]);
        $openAlways = Department::query()->create([
            'name' => 'Служба 24/7',
            'is_active' => true,
            'work_schedule_enabled' => false,
        ]);
        $sales->users()->sync([$manager->id]);

        $session = WhatsappSession::factory()->create();
        $chat = Chat::factory()->create([
            'company_id' => $company->id,
            'whatsapp_session_id' => $session->id,
            'ai_enabled' => true,
        ]);
        $chat->departments()->sync([$openAlways->id]);

        $trigger = Message::create([
            'chat_id' => $chat->id,
            'whatsapp_session_id' => $session->id,
            'direction' => 'inbound',
            'type' => 'chat',
            'body' => 'Хочу купить кухню',
            'ack' => 'delivered',
            'message_timestamp' => now(),
        ]);

        \Illuminate\Support\Facades\Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [
                    ['message' => ['content' => json_encode([
                        'department_id' => $sales->id,
                        'confidence' => 0.92,
                        'should_assign' => true,
                        'reason' => 'Клиент интересуется покупкой.',
                    ], JSON_THROW_ON_ERROR)]],
                ],
            ]),
        ]);

        $routing = $this->app->make(\App\Services\AI\ChatDepartmentRoutingService::class);
        $department = $routing->resolveAndAssignDepartment($chat, $trigger);

        $this->assertSame($sales->id, (int) $department?->id);
        $this->assertDatabaseHas('chat_department', [
            'chat_id' => $chat->id,
            'department_id' => $sales->id,
        ]);

        $sent = $this->app->make(ChatOffHoursReplyService::class)->tryReply($chat, $trigger, $department);
        $this->assertTrue($sent);

        $outbound = Message::query()
            ->where('chat_id', $chat->id)
            ->where('direction', 'outbound')
            ->latest('id')
            ->first();

        $this->assertStringContainsString('Продажи', (string) $outbound?->body);
        $this->assertStringNotContainsString('24/7', (string) $outbound?->body);

        Carbon::setTestNow();
    }

    public function test_inbound_job_skips_orchestrator_when_off_hours(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-17 21:00:00', 'Asia/Almaty'));
        Queue::fake();

        $company = Company::create(['name' => 'Company']);
        $manager = User::factory()->create(['company_id' => $company->id, 'is_active' => true]);
        $manager->assignRole('manager');

        $department = Department::query()->create([
            'name' => 'Продажи',
            'is_active' => true,
            'work_schedule_enabled' => true,
            'work_schedule_timezone' => 'Asia/Almaty',
            'work_schedule' => DepartmentWorkSchedule::defaultWeek(),
        ]);
        $department->users()->sync([$manager->id]);

        $session = WhatsappSession::factory()->create();
        $chat = Chat::factory()->create([
            'company_id' => $company->id,
            'whatsapp_session_id' => $session->id,
            'whatsapp_chat_id' => '77001112233@c.us',
            'ai_enabled' => true,
            'funnel_tracking_enabled' => true,
        ]);
        $chat->departments()->sync([$department->id]);

        $job = new ProcessWhatsappInboundJob([
            'session' => $session->session_name,
            'chatId' => $chat->whatsapp_chat_id,
            'chatName' => $chat->chat_name,
            'from' => '77001112233',
            'body' => 'Нужна консультация',
            'type' => 'chat',
            'messageId' => 'off-hours-1',
            'timestamp' => now()->getTimestamp(),
            'isGroup' => false,
        ]);
        $this->app->call([$job, 'handle']);

        Queue::assertNotPushed(\App\Jobs\RunAiFunnelOrchestratorJob::class);
        Queue::assertNotPushed(\App\Jobs\AnalyzeChatFunnelJob::class);
        Queue::assertNotPushed(\App\Jobs\GenerateAiReplyJob::class);

        $this->assertDatabaseHas('messages', [
            'chat_id' => $chat->id,
            'direction' => 'outbound',
        ]);

        Carbon::setTestNow();
    }
}
