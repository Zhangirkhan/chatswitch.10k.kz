<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\Jobs\GenerateAiReplyJob;
use App\Jobs\GenerateFirstContactAckJob;
use App\Jobs\ProcessWhatsappInboundJob;
use App\Jobs\RunAiFunnelOrchestratorJob;
use App\Models\AiResponseLog;
use App\Models\Chat;
use App\Models\Message;
use App\Models\User;
use App\Models\WhatsappSession;
use App\Services\AI\ChatFirstContactAckService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class FirstContactAckTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('manager');
        config()->set('services.openai.api_key', 'test-key');
        config(['funnel.department_routing.enabled' => false]);
    }

    public function test_inbound_dispatches_first_contact_ack_when_ai_disabled(): void
    {
        WhatsappSession::factory()->create(['session_name' => 'default']);

        Bus::fake([
            GenerateFirstContactAckJob::class,
            GenerateAiReplyJob::class,
            RunAiFunnelOrchestratorJob::class,
        ]);

        $this->runInboundJob('77771112233@c.us', 'wa-first-ack-1', 'Здравствуйте');

        Bus::assertDispatched(GenerateFirstContactAckJob::class);
        Bus::assertNotDispatched(GenerateAiReplyJob::class);
    }

    public function test_inbound_dispatches_first_contact_ack_in_draft_mode(): void
    {
        $company = $this->createTenantCompany(['name' => 'Draft Co']);
        $employee = User::factory()->create(['company_id' => $company->id, 'is_active' => true]);
        $employee->assignRole('manager');
        $session = WhatsappSession::factory()->create(['company_id' => $company->id, 'session_name' => 'default']);
        $chat = Chat::factory()->create([
            'company_id' => $company->id,
            'whatsapp_session_id' => $session->id,
            'whatsapp_chat_id' => '77772223344@c.us',
            'ai_enabled' => true,
            'ai_mode' => 'draft',
            'ai_responder_user_id' => $employee->id,
        ]);

        Bus::fake([
            GenerateFirstContactAckJob::class,
            GenerateAiReplyJob::class,
        ]);

        $this->runInboundJob('77772223344@c.us', 'wa-first-ack-draft', 'Нужен замер', $chat);

        Bus::assertDispatched(GenerateFirstContactAckJob::class);
    }

    public function test_inbound_skips_first_contact_ack_when_ai_auto_enabled(): void
    {
        $company = $this->createTenantCompany(['name' => 'Auto Co']);
        $employee = User::factory()->create(['company_id' => $company->id, 'is_active' => true]);
        $employee->assignRole('manager');
        $session = WhatsappSession::factory()->create(['company_id' => $company->id, 'session_name' => 'default']);
        $chat = Chat::factory()->create([
            'company_id' => $company->id,
            'whatsapp_session_id' => $session->id,
            'whatsapp_chat_id' => '77773334455@c.us',
            'ai_enabled' => true,
            'ai_mode' => 'auto',
            'ai_responder_user_id' => $employee->id,
        ]);

        Bus::fake([
            GenerateFirstContactAckJob::class,
            GenerateAiReplyJob::class,
        ]);

        $this->runInboundJob('77773334455@c.us', 'wa-first-ack-auto', 'Здравствуйте', $chat);

        Bus::assertNotDispatched(GenerateFirstContactAckJob::class);
    }

    public function test_first_contact_ack_job_sends_outbound_message(): void
    {
        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [
                    ['message' => ['content' => 'Здравствуйте! Получили ваше сообщение, менеджер скоро свяжется.']],
                ],
            ]),
        ]);

        $company = $this->createTenantCompany(['name' => 'Ack Co']);
        $employee = User::factory()->create(['company_id' => $company->id, 'is_active' => true]);
        $employee->assignRole('manager');
        $session = WhatsappSession::factory()->create(['company_id' => $company->id]);
        $chat = Chat::factory()->create([
            'company_id' => $company->id,
            'whatsapp_session_id' => $session->id,
            'ai_enabled' => false,
            'ai_mode' => 'auto',
            'ai_responder_user_id' => $employee->id,
        ]);
        $trigger = Message::query()->create([
            'chat_id' => $chat->id,
            'whatsapp_session_id' => $session->id,
            'direction' => 'inbound',
            'type' => 'chat',
            'body' => 'Здравствуйте, интересует кухня',
            'message_timestamp' => now(),
        ]);

        $job = new GenerateFirstContactAckJob($chat->id, $trigger->id, $company->id);
        $this->app->call([$job, 'handle']);

        $log = AiResponseLog::query()
            ->where('trigger_message_id', $trigger->id)
            ->where('mode', ChatFirstContactAckService::LOG_MODE)
            ->first();

        $this->assertNotNull($log);
        $this->assertSame('sent', $log->status);
        $this->assertNotNull($log->message_id);

        $outbound = Message::query()->whereKey($log->message_id)->first();
        $this->assertNotNull($outbound);
        $this->assertSame('outbound', $outbound->direction);
        $this->assertSame('first_contact_ack', data_get($outbound->metadata, 'ai.mode'));
    }

    public function test_second_inbound_does_not_get_first_contact_ack(): void
    {
        $company = $this->createTenantCompany(['name' => 'Second Co']);
        $employee = User::factory()->create(['company_id' => $company->id, 'is_active' => true]);
        $employee->assignRole('manager');
        $session = WhatsappSession::factory()->create(['company_id' => $company->id, 'session_name' => 'default']);
        $chat = Chat::factory()->create([
            'company_id' => $company->id,
            'whatsapp_session_id' => $session->id,
            'whatsapp_chat_id' => '77774445566@c.us',
            'ai_enabled' => false,
        ]);
        Message::query()->create([
            'chat_id' => $chat->id,
            'whatsapp_session_id' => $session->id,
            'direction' => 'outbound',
            'type' => 'chat',
            'body' => 'Первый ответ компании',
            'sent_by_user_id' => $employee->id,
            'message_timestamp' => now()->subMinute(),
        ]);

        Bus::fake([GenerateFirstContactAckJob::class]);

        $this->runInboundJob('77774445566@c.us', 'wa-second-inbound', 'А цена?', $chat);

        Bus::assertNotDispatched(GenerateFirstContactAckJob::class);
    }

    private function runInboundJob(string $chatId, string $messageId, string $body, ?Chat $existingChat = null): void
    {
        if ($existingChat === null && ! WhatsappSession::query()->where('session_name', 'default')->exists()) {
            WhatsappSession::factory()->create(['session_name' => 'default']);
        }

        $data = [
            'session' => 'default',
            'chatId' => $chatId,
            'chatName' => 'Client',
            'from' => $chatId,
            'senderPhone' => preg_replace('/\D/', '', $chatId),
            'senderName' => 'Client',
            'body' => $body,
            'isGroup' => false,
            'timestamp' => time(),
            'messageId' => $messageId,
        ];

        $job = new ProcessWhatsappInboundJob($data);
        $this->app->call([$job, 'handle']);
    }
}
