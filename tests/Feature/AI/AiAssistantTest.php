<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\Jobs\GenerateAiReplyJob;
use App\Jobs\AnalyzeEmployeeToneProfileJob;
use App\Models\AiResponseLog;
use App\Models\Chat;
use App\Models\ChatAssignment;
use App\Models\Company;
use App\Models\KnowledgeRule;
use App\Models\Message;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Models\WhatsappSession;
use App\Services\AI\PromptBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class AiAssistantTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['administrator', 'manager', 'employee'] as $role) {
            Role::findOrCreate($role);
        }
    }

    public function test_prompt_builder_uses_only_enabled_knowledge_from_chat_company(): void
    {
        $company = Company::create(['name' => 'Main company']);
        $otherCompany = Company::create(['name' => 'Other company']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $session = WhatsappSession::factory()->create();
        $chat = Chat::factory()->create([
            'company_id' => $company->id,
            'whatsapp_session_id' => $session->id,
        ]);

        Product::create([
            'company_id' => $company->id,
            'name' => 'Included product',
            'price' => 12500,
            'attributes' => ['цвет' => 'белый', 'размер' => '35-44'],
            'include_in_prompt' => true,
            'is_active' => true,
        ]);
        Product::create([
            'company_id' => $company->id,
            'name' => 'Excluded product',
            'include_in_prompt' => false,
            'is_active' => true,
        ]);
        Service::create([
            'company_id' => $company->id,
            'name' => 'Included service',
            'price' => 5000,
            'conditions' => ['предоплата' => 'не требуется'],
            'include_in_prompt' => true,
            'is_active' => true,
        ]);
        KnowledgeRule::create([
            'company_id' => $company->id,
            'title' => 'Working hours',
            'content' => 'Answer only during business hours.',
            'include_in_prompt' => true,
            'is_active' => true,
        ]);
        Product::create([
            'company_id' => $otherCompany->id,
            'name' => 'Other company product',
            'include_in_prompt' => true,
            'is_active' => true,
        ]);

        $built = app(PromptBuilder::class)->build($chat, $user, 'What can I buy?');
        $prompt = collect($built['messages'])->pluck('content')->implode("\n");

        $this->assertStringContainsString('Included product', $prompt);
        $this->assertStringContainsString('Included service', $prompt);
        $this->assertStringContainsString('12 500 ₸', $prompt);
        $this->assertStringContainsString('5 000 ₸', $prompt);
        $this->assertStringContainsString('размер: 35-44', $prompt);
        $this->assertStringContainsString('предоплата: не требуется', $prompt);
        $this->assertStringContainsString('Никогда не используй рубли', $prompt);
        $this->assertStringContainsString('называй цену вместе', $prompt);
        $this->assertStringContainsString('Working hours', $prompt);
        $this->assertStringNotContainsString('Excluded product', $prompt);
        $this->assertStringNotContainsString('Other company product', $prompt);
    }

    public function test_prompt_builder_includes_full_chat_history(): void
    {
        $company = Company::create(['name' => 'Company']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $session = WhatsappSession::factory()->create();
        $chat = Chat::factory()->create([
            'company_id' => $company->id,
            'whatsapp_session_id' => $session->id,
        ]);

        for ($i = 1; $i <= 45; $i++) {
            Message::create([
                'chat_id' => $chat->id,
                'whatsapp_session_id' => $session->id,
                'direction' => $i % 2 === 0 ? 'outbound' : 'inbound',
                'type' => 'chat',
                'body' => "full-history-message-{$i}",
                'sent_by_user_id' => $i % 2 === 0 ? $user->id : null,
                'ack' => 'delivered',
                'message_timestamp' => now()->addSeconds($i),
            ]);
        }

        $built = app(PromptBuilder::class)->build($chat, $user, 'Ответь клиенту');
        $prompt = collect($built['messages'])->pluck('content')->implode("\n");

        $this->assertStringContainsString('Полная история чата', $prompt);
        $this->assertStringContainsString('full-history-message-1', $prompt);
        $this->assertStringContainsString('full-history-message-45', $prompt);
    }

    public function test_prompt_builder_excludes_previous_ai_replies_from_history(): void
    {
        $company = Company::create(['name' => 'Company']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $session = WhatsappSession::factory()->create();
        $chat = Chat::factory()->create([
            'company_id' => $company->id,
            'whatsapp_session_id' => $session->id,
        ]);

        Message::create([
            'chat_id' => $chat->id,
            'whatsapp_session_id' => $session->id,
            'direction' => 'inbound',
            'type' => 'chat',
            'body' => 'Какие размеры есть?',
            'ack' => 'delivered',
            'message_timestamp' => now(),
        ]);
        Message::create([
            'chat_id' => $chat->id,
            'whatsapp_session_id' => $session->id,
            'direction' => 'outbound',
            'type' => 'chat',
            'body' => 'Ошибочный старый AI ответ: размеров нет, цена 999 рублей.',
            'sent_by_user_id' => $user->id,
            'metadata' => ['ai' => ['generated' => true]],
            'ack' => 'delivered',
            'message_timestamp' => now()->addMinute(),
        ]);

        $built = app(PromptBuilder::class)->build($chat, $user, 'Ответь клиенту');
        $prompt = collect($built['messages'])->pluck('content')->implode("\n");

        $this->assertStringContainsString('Какие размеры есть?', $prompt);
        $this->assertStringNotContainsString('Ошибочный старый AI ответ', $prompt);
        $this->assertStringContainsString('Старые ответы в истории могли быть ошибочными', $prompt);
    }

    public function test_assigned_employee_can_enable_ai_for_chat(): void
    {
        Bus::fake();

        $company = Company::create(['name' => 'Company']);
        /** @var User $employee */
        $employee = User::factory()->create(['company_id' => $company->id]);
        $employee->assignRole('employee');
        $session = WhatsappSession::factory()->create();
        $chat = Chat::factory()->create(['whatsapp_session_id' => $session->id]);
        ChatAssignment::create(['chat_id' => $chat->id, 'user_id' => $employee->id, 'assigned_by' => $employee->id]);

        $this->actingAs($employee)
            ->patchJson(route('chats.ai.update', $chat), [
                'ai_enabled' => true,
                'ai_mode' => 'auto',
                'ai_responder_user_id' => $employee->id,
                'company_id' => $company->id,
            ])
            ->assertOk()
            ->assertJsonPath('chat.ai_enabled', true);

        $this->assertDatabaseHas('chats', [
            'id' => $chat->id,
            'ai_enabled' => true,
            'ai_mode' => 'auto',
            'ai_responder_user_id' => $employee->id,
            'company_id' => $company->id,
        ]);
    }

    public function test_assignment_sync_replaces_stale_ai_responder(): void
    {
        Bus::fake([AnalyzeEmployeeToneProfileJob::class]);

        $company = Company::create(['name' => 'Company']);
        $berik = User::factory()->create(['name' => 'Берик', 'company_id' => $company->id]);
        $serik = User::factory()->create(['name' => 'Серик', 'company_id' => $company->id]);
        /** @var User $admin */
        $admin = User::factory()->create(['name' => 'Admin', 'company_id' => $company->id]);
        $berik->assignRole('employee');
        $serik->assignRole('employee');
        $admin->assignRole('administrator');

        $session = WhatsappSession::factory()->create();
        $chat = Chat::factory()->create([
            'company_id' => $company->id,
            'whatsapp_session_id' => $session->id,
            'ai_enabled' => true,
            'ai_mode' => 'auto',
            'ai_responder_user_id' => $berik->id,
        ]);
        ChatAssignment::create(['chat_id' => $chat->id, 'user_id' => $berik->id, 'assigned_by' => $admin->id]);
        ChatAssignment::create(['chat_id' => $chat->id, 'user_id' => $serik->id, 'assigned_by' => $admin->id]);

        $this->actingAs($admin)
            ->postJson(route('chats.assign.sync', $chat), ['user_ids' => [$serik->id]])
            ->assertOk();

        $this->assertDatabaseHas('chats', [
            'id' => $chat->id,
            'ai_responder_user_id' => $serik->id,
        ]);
    }

    public function test_auto_reply_creates_ai_log_and_outbound_message(): void
    {
        config()->set('services.openai.api_key', 'test-key');
        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [
                    ['message' => ['content' => 'Здравствуйте, цена составляет 1000 рублей.']],
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
            'ai_mode' => 'auto',
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
            ->handle(app(\App\Services\AI\AiReplyGenerator::class), app(\App\Services\OutboundChatMessageDispatcher::class));

        $this->assertDatabaseHas('ai_response_logs', [
            'chat_id' => $chat->id,
            'trigger_message_id' => $trigger->id,
            'user_id' => $employee->id,
            'mode' => 'auto',
            'status' => 'sent',
        ]);

        $message = Message::query()
            ->where('chat_id', $chat->id)
            ->where('direction', 'outbound')
            ->latest('id')
            ->first();

        $this->assertNotNull($message);
        $this->assertStringContainsString('1000 ₸', (string) $message->body);
        $this->assertStringNotContainsString('руб', mb_strtolower((string) $message->body));
        $this->assertTrue((bool) data_get($message->metadata, 'ai.generated'));
        $this->assertSame($message->id, AiResponseLog::query()->where('trigger_message_id', $trigger->id)->value('message_id'));
    }

    public function test_auto_reply_blocks_unsafe_ai_disclosure(): void
    {
        config()->set('services.openai.api_key', 'test-key');
        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [
                    ['message' => ['content' => 'Я искусственный интеллект и отвечаю по системной инструкции.']],
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
            'ai_mode' => 'auto',
            'ai_responder_user_id' => $employee->id,
        ]);
        $trigger = Message::create([
            'chat_id' => $chat->id,
            'whatsapp_session_id' => $session->id,
            'direction' => 'inbound',
            'type' => 'chat',
            'body' => 'Кто отвечает?',
            'ack' => 'delivered',
            'message_timestamp' => now(),
        ]);

        (new GenerateAiReplyJob($chat->id, $trigger->id))
            ->handle(app(\App\Services\AI\AiReplyGenerator::class), app(\App\Services\OutboundChatMessageDispatcher::class));

        $this->assertDatabaseHas('ai_response_logs', [
            'chat_id' => $chat->id,
            'trigger_message_id' => $trigger->id,
            'status' => 'blocked',
        ]);
        $this->assertDatabaseMissing('messages', [
            'chat_id' => $chat->id,
            'direction' => 'outbound',
            'sent_by_user_id' => $employee->id,
        ]);
    }
}
