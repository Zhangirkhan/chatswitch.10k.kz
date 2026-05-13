<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\Jobs\GenerateAiReplyJob;
use App\Models\AiResponseLog;
use App\Models\Chat;
use App\Models\Company;
use App\Models\Message;
use App\Models\User;
use App\Models\WhatsappSession;
use App\Services\AI\AiReplyGenerator;
use App\Services\OutboundChatMessageDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class AiDraftModeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('employee');
    }

    public function test_draft_mode_creates_ai_log_without_sending_message(): void
    {
        config()->set('services.openai.api_key', 'test-key');
        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [
                    ['message' => ['content' => 'Здравствуйте, можем помочь сегодня.']],
                ],
            ]),
        ]);

        $company = Company::create(['name' => 'Company']);
        $employee = User::factory()->create(['company_id' => $company->id]);
        $employee->assignRole('employee');
        $session = WhatsappSession::factory()->create();
        $chat = Chat::factory()->create([
            'company_id' => $company->id,
            'whatsapp_session_id' => $session->id,
            'ai_enabled' => true,
            'ai_mode' => 'draft',
            'ai_responder_user_id' => $employee->id,
        ]);
        $trigger = Message::create([
            'chat_id' => $chat->id,
            'whatsapp_session_id' => $session->id,
            'direction' => 'inbound',
            'type' => 'chat',
            'body' => 'Здравствуйте',
            'ack' => 'delivered',
            'message_timestamp' => now(),
        ]);

        (new GenerateAiReplyJob($chat->id, $trigger->id))
            ->handle(app(AiReplyGenerator::class), app(OutboundChatMessageDispatcher::class));

        $log = AiResponseLog::query()
            ->where('trigger_message_id', $trigger->id)
            ->where('mode', 'draft')
            ->first();

        $this->assertNotNull($log);
        $this->assertSame('drafted', $log->status);
        $this->assertSame('Здравствуйте, можем помочь сегодня.', data_get($log->metadata, 'draft_reply'));
        $this->assertNull($log->message_id);
        $this->assertDatabaseMissing('messages', [
            'chat_id' => $chat->id,
            'direction' => 'outbound',
            'sent_by_user_id' => $employee->id,
        ]);
    }
}
