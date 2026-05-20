<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\Jobs\AnalyzeCompanyToneProfileJob;
use App\Jobs\AnalyzeEmployeeToneProfileJob;
use App\Jobs\GenerateAiReplyJob;
use App\Jobs\SendOutboundMessageJob;
use App\Models\AiResponseLog;
use App\Models\CalendarEvent;
use App\Models\Chat;
use App\Models\ChatAssignment;
use App\Models\Company;
use App\Models\CompanyToneProfile;
use App\Models\Contact;
use App\Models\EmployeeToneProfile;
use App\Models\KnowledgeRule;
use App\Models\Message;
use App\Models\Product;
use App\Models\ScheduledMessage;
use App\Models\Service;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\WhatsappSession;
use App\Services\AI\AiAppointmentIntentService;
use App\Services\AI\PromptBuilder;
use App\Services\AI\ToneProfileAnalyzer;
use App\Services\Calendar\AppointmentBookingService;
use App\Services\Calendar\AppointmentReminderSettings;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
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

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
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

    public function test_prompt_compression_caches_long_conversation_summary(): void
    {
        Cache::flush();

        $company = Company::create(['name' => 'Company']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $session = WhatsappSession::factory()->create();
        $chat = Chat::factory()->create([
            'company_id' => $company->id,
            'whatsapp_session_id' => $session->id,
        ]);

        $longBody = str_repeat('Длинное сообщение клиента о товаре и цене. ', 40);
        for ($i = 1; $i <= 120; $i++) {
            Message::create([
                'chat_id' => $chat->id,
                'whatsapp_session_id' => $session->id,
                'direction' => $i % 2 === 0 ? 'outbound' : 'inbound',
                'type' => 'chat',
                'body' => "{$longBody} #{$i}",
                'sent_by_user_id' => $i % 2 === 0 ? $user->id : null,
                'ack' => 'delivered',
                'message_timestamp' => now()->addSeconds($i),
            ]);
        }

        $openAiCalls = 0;
        Http::fake(function () use (&$openAiCalls) {
            $openAiCalls++;

            return Http::response([
                'choices' => [
                    ['message' => ['content' => 'Сжатая сводка переписки для теста.']],
                ],
            ], 200);
        });

        $builder = app(PromptBuilder::class);
        $builder->build($chat, $user, 'Ответь');
        $callsAfterFirst = $openAiCalls;
        $builder->build($chat, $user, 'Ответь снова');
        $callsAfterSecond = $openAiCalls;

        $this->assertGreaterThan(0, $callsAfterFirst);
        $this->assertSame($callsAfterFirst, $callsAfterSecond);
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
        $conversationContext = $built['messages'][1]['content'];
        $continuityContext = $built['messages'][2]['content'];

        $this->assertStringContainsString('Какие размеры есть?', $conversationContext);
        $this->assertStringNotContainsString('Ошибочный старый AI ответ', $conversationContext);
        $this->assertStringContainsString('Ошибочный старый AI ответ', $continuityContext);
        $this->assertStringContainsString('Используй только чтобы не повторяться', $continuityContext);
        $this->assertStringContainsString('Старые ответы в истории могли быть ошибочными', $built['messages'][0]['content']);
    }

    public function test_prompt_builder_includes_recent_ai_replies_only_for_continuity(): void
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
            'direction' => 'outbound',
            'type' => 'chat',
            'body' => 'Тапочки стоят 1 000 ₸, размеры 35-44.',
            'sent_by_user_id' => $user->id,
            'metadata' => ['ai' => ['generated' => true]],
            'ack' => 'delivered',
            'message_timestamp' => now(),
        ]);

        $built = app(PromptBuilder::class)->build($chat, $user, '2 пары 40 размера');

        $this->assertStringNotContainsString('Тапочки стоят 1 000 ₸', $built['messages'][1]['content']);
        $this->assertStringContainsString('Тапочки стоят 1 000 ₸', $built['messages'][2]['content']);
        $this->assertStringContainsString('Не повторяй уже сказанные клиенту', $built['messages'][0]['content']);
        $this->assertStringContainsString('факты бери из базы знаний', $built['messages'][0]['content']);
    }

    public function test_prompt_builder_includes_recent_manual_responder_style_examples(): void
    {
        $company = Company::create(['name' => 'Company']);
        $responder = User::factory()->create(['company_id' => $company->id, 'name' => 'Serik']);
        $otherUser = User::factory()->create(['company_id' => $company->id]);
        $session = WhatsappSession::factory()->create();
        $chat = Chat::factory()->create([
            'company_id' => $company->id,
            'whatsapp_session_id' => $session->id,
        ]);

        Message::create([
            'chat_id' => $chat->id,
            'whatsapp_session_id' => $session->id,
            'direction' => 'outbound',
            'type' => 'chat',
            'body' => 'че там братан',
            'sent_by_user_id' => $responder->id,
            'ack' => 'delivered',
            'message_timestamp' => now(),
        ]);
        Message::create([
            'chat_id' => $chat->id,
            'whatsapp_session_id' => $session->id,
            'direction' => 'outbound',
            'type' => 'chat',
            'body' => 'AI-шаблон: Здравствуйте, спасибо за интерес.',
            'sent_by_user_id' => $responder->id,
            'metadata' => ['ai' => ['generated' => true]],
            'ack' => 'delivered',
            'message_timestamp' => now()->addMinute(),
        ]);
        Message::create([
            'chat_id' => $chat->id,
            'whatsapp_session_id' => $session->id,
            'direction' => 'outbound',
            'type' => 'chat',
            'body' => 'чужой стиль не брать',
            'sent_by_user_id' => $otherUser->id,
            'ack' => 'delivered',
            'message_timestamp' => now()->addMinutes(2),
        ]);

        $built = app(PromptBuilder::class)->build($chat, $responder, 'как дела');
        $prompt = $built['messages'][0]['content'];

        $this->assertStringContainsString('главный источник стиля', $prompt);
        $this->assertStringContainsString('че там братан', $prompt);
        $this->assertStringContainsString('Не используй шаблонные AI-фразы', $prompt);
        $this->assertStringNotContainsString('AI-шаблон', $prompt);
        $this->assertStringNotContainsString('чужой стиль не брать', $prompt);
    }

    public function test_prompt_builder_uses_company_tone_until_employee_profile_is_collected(): void
    {
        $company = Company::create(['name' => 'Company']);
        $responder = User::factory()->create(['company_id' => $company->id, 'name' => 'New manager']);
        $session = WhatsappSession::factory()->create();
        $chat = Chat::factory()->create([
            'company_id' => $company->id,
            'whatsapp_session_id' => $session->id,
        ]);

        CompanyToneProfile::create([
            'company_id' => $company->id,
            'summary' => 'Компания пишет коротко, дружелюбно и сразу по делу.',
            'phrases' => ['Да, конечно', 'Сейчас уточню', 'Записала вас'],
            'metadata' => ['samples_count' => 20, 'source' => 'openai'],
            'analyzed_at' => now(),
        ]);
        EmployeeToneProfile::create([
            'company_id' => $company->id,
            'user_id' => $responder->id,
            'summary' => 'Недостаточно исходящих сообщений. Использовать нейтральный стиль.',
            'phrases' => [],
            'metadata' => ['samples_count' => 0, 'source' => 'fallback'],
            'analyzed_at' => now(),
        ]);

        $built = app(PromptBuilder::class)->build($chat, $responder, 'Здравствуйте');
        $prompt = collect($built['messages'])->pluck('content')->implode("\n");

        $this->assertStringContainsString('Временно используй общий стиль компании', $prompt);
        $this->assertStringContainsString('Компания пишет коротко', $prompt);
        $this->assertStringContainsString('Записала вас', $prompt);
        $this->assertStringNotContainsString('Недостаточно исходящих сообщений', $prompt);
    }

    public function test_company_tone_profile_is_built_from_manual_company_messages(): void
    {
        config()->set('services.openai.api_key', 'test-key');
        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [
                    ['message' => ['content' => json_encode([
                        'summary' => 'Общий стиль компании: коротко, дружелюбно, без длинных вступлений.',
                        'phrases' => ['Да, конечно', 'Сейчас уточню'],
                    ], JSON_THROW_ON_ERROR)]],
                ],
            ]),
        ]);

        $company = Company::create(['name' => 'Company']);
        $employee = User::factory()->create(['company_id' => $company->id]);
        $session = WhatsappSession::factory()->create();
        $chat = Chat::factory()->create([
            'company_id' => $company->id,
            'whatsapp_session_id' => $session->id,
        ]);
        Message::create([
            'chat_id' => $chat->id,
            'whatsapp_session_id' => $session->id,
            'direction' => 'outbound',
            'type' => 'chat',
            'body' => 'Да, конечно, сейчас уточню',
            'sent_by_user_id' => $employee->id,
            'ack' => 'delivered',
            'message_timestamp' => now(),
        ]);
        Message::create([
            'chat_id' => $chat->id,
            'whatsapp_session_id' => $session->id,
            'direction' => 'outbound',
            'type' => 'chat',
            'body' => 'AI-шаблон не должен попадать в общий стиль',
            'sent_by_user_id' => $employee->id,
            'metadata' => ['ai' => ['generated' => true]],
            'ack' => 'delivered',
            'message_timestamp' => now()->addMinute(),
        ]);

        $profile = app(ToneProfileAnalyzer::class)->analyzeCompany($company->id);

        $this->assertStringContainsString('коротко', (string) $profile->summary);
        $this->assertSame(1, (int) data_get($profile->metadata, 'samples_count'));
        $this->assertSame(['Да, конечно', 'Сейчас уточню'], $profile->phrases);
    }

    public function test_enabling_ai_without_readiness_requires_confirmation(): void
    {
        Bus::fake();

        $company = Company::create(['name' => 'Company']);
        /** @var User $employee */
        $employee = User::factory()->create(['company_id' => $company->id]);
        $employee->assignRole('employee');
        $session = WhatsappSession::factory()->create();
        $chat = Chat::factory()->create([
            'whatsapp_session_id' => $session->id,
            'company_id' => $company->id,
            'ai_enabled' => false,
        ]);
        ChatAssignment::create(['chat_id' => $chat->id, 'user_id' => $employee->id, 'assigned_by' => $employee->id]);

        $this->actingAs($employee)
            ->patchJson(route('chats.ai.update', $chat), [
                'ai_enabled' => true,
                'ai_mode' => 'auto',
                'ai_responder_user_id' => $employee->id,
                'company_id' => $company->id,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('requires_confirmation', true);
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
                'confirm_risky_enable' => true,
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
        Bus::fake([AnalyzeCompanyToneProfileJob::class, AnalyzeEmployeeToneProfileJob::class]);

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

        $job = new GenerateAiReplyJob($chat->id, $trigger->id);
        $this->app->call([$job, 'handle']);

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

        $job = new GenerateAiReplyJob($chat->id, $trigger->id);
        $this->app->call([$job, 'handle']);

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

    public function test_ai_creates_calendar_event_and_reminder_after_explicit_booking_confirmation(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-13 10:00:00', 'Asia/Almaty'));
        config()->set('app.timezone', 'Asia/Almaty');
        config()->set('services.openai.api_key', 'test-key');
        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [
                    ['message' => ['content' => json_encode([
                        'is_appointment_request' => true,
                        'has_explicit_confirmation' => true,
                        'service_name' => 'Массаж',
                        'starts_at' => '2026-05-13T13:00:00+05:00',
                        'duration_minutes' => 90,
                        'missing_fields' => [],
                        'client_reply' => 'Записала вас на массаж сегодня в 13:00. Напомним за час.',
                        'client_note' => 'Клиент подтвердил время.',
                    ], JSON_THROW_ON_ERROR)]],
                ],
            ]),
        ]);

        $company = Company::create(['name' => 'Company']);
        $employee = User::factory()->create(['company_id' => $company->id]);
        $employee->assignRole('employee');
        $session = WhatsappSession::factory()->create();
        $contact = Contact::create(['whatsapp_id' => '77010000000@c.us', 'name' => 'Айжан', 'phone_number' => '77010000000']);
        $chat = Chat::factory()->create([
            'company_id' => $company->id,
            'contact_id' => $contact->id,
            'whatsapp_session_id' => $session->id,
            'chat_name' => 'Айжан',
            'ai_enabled' => true,
            'ai_mode' => 'auto',
            'ai_responder_user_id' => $employee->id,
        ]);
        Service::create([
            'company_id' => $company->id,
            'name' => 'Массаж',
            'duration_minutes' => 90,
            'include_in_prompt' => true,
            'is_active' => true,
        ]);
        $trigger = Message::create([
            'chat_id' => $chat->id,
            'whatsapp_session_id' => $session->id,
            'direction' => 'inbound',
            'type' => 'chat',
            'body' => 'Да, запишите меня сегодня на 13:00 на массаж',
            'ack' => 'delivered',
            'message_timestamp' => now(),
        ]);

        $job = new GenerateAiReplyJob($chat->id, $trigger->id);
        $this->app->call([$job, 'handle']);

        $event = CalendarEvent::query()->where('trigger_message_id', $trigger->id)->first();
        $this->assertNotNull($event);
        $this->assertSame(CalendarEvent::SOURCE_AI_AUTO, $event->source);
        $this->assertSame($chat->id, $event->chat_id);
        $this->assertSame($contact->id, $event->contact_id);
        $this->assertSame($employee->id, $event->assignee_user_id);
        $this->assertSame(90, (int) data_get($event->metadata, 'ai.duration_minutes'));

        $reminder = ScheduledMessage::query()->where('calendar_event_id', $event->id)->first();
        $this->assertNotNull($reminder);
        $this->assertSame(ScheduledMessage::PURPOSE_APPOINTMENT_REMINDER, $reminder->purpose);
        $this->assertSame(ScheduledMessage::STATUS_PENDING, $reminder->status);
        $this->assertTrue($reminder->scheduled_at->equalTo(Carbon::parse('2026-05-13T12:00:00+05:00')));
        $this->assertStringContainsString('13.05 в 13:00', $reminder->body);
        $this->assertStringNotContainsString('сегодня', mb_strtolower($reminder->body));

        $message = Message::query()->where('chat_id', $chat->id)->where('direction', 'outbound')->latest('id')->first();
        $this->assertNotNull($message);
        $this->assertStringContainsString('13.05 в 13:00', (string) $message->body);
        $this->assertStringNotContainsString('сегодня', mb_strtolower((string) $message->body));
        $this->assertStringNotContainsString('завтра', mb_strtolower((string) $message->body));
        $this->assertSame('booked', data_get($message->metadata, 'appointment.status'));
        $this->assertSame($event->id, data_get($message->metadata, 'appointment.calendar_event_id'));
    }

    public function test_appointment_reminder_uses_system_lead_time_setting(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-13 10:00:00', 'Asia/Almaty'));
        config()->set('app.timezone', 'Asia/Almaty');
        SystemSetting::setValue(AppointmentReminderSettings::LEAD_TIME_MINUTES_KEY, '30');

        $company = Company::create(['name' => 'Company']);
        $employee = User::factory()->create(['company_id' => $company->id]);
        $session = WhatsappSession::factory()->create();
        $contact = Contact::create(['whatsapp_id' => '77010000000@c.us', 'name' => 'Айжан', 'phone_number' => '77010000000']);
        $chat = Chat::factory()->create([
            'company_id' => $company->id,
            'contact_id' => $contact->id,
            'whatsapp_session_id' => $session->id,
            'chat_name' => 'Айжан',
        ]);
        $trigger = Message::create([
            'chat_id' => $chat->id,
            'whatsapp_session_id' => $session->id,
            'direction' => 'inbound',
            'type' => 'chat',
            'body' => 'Да, запишите меня сегодня на 13:00 на массаж',
            'ack' => 'delivered',
            'message_timestamp' => now(),
        ]);

        $booking = app(AppointmentBookingService::class)->book(
            $chat,
            $employee,
            $trigger,
            'Массаж',
            Carbon::parse('2026-05-13T13:00:00+05:00'),
            90,
        );

        $this->assertNotNull($booking['reminder']);
        $this->assertTrue($booking['reminder']->scheduled_at->equalTo(Carbon::parse('2026-05-13T12:30:00+05:00')));
    }

    public function test_appointment_reminder_uses_client_requested_lead_time(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-13 10:00:00', 'Asia/Almaty'));
        config()->set('app.timezone', 'Asia/Almaty');
        SystemSetting::setValue(AppointmentReminderSettings::LEAD_TIME_MINUTES_KEY, '60');

        $company = Company::create(['name' => 'Company']);
        $employee = User::factory()->create(['company_id' => $company->id]);
        $session = WhatsappSession::factory()->create();
        $contact = Contact::create(['whatsapp_id' => '77010000001@c.us', 'name' => 'Айжан', 'phone_number' => '77010000001']);
        $chat = Chat::factory()->create([
            'company_id' => $company->id,
            'contact_id' => $contact->id,
            'whatsapp_session_id' => $session->id,
        ]);
        $trigger = Message::create([
            'chat_id' => $chat->id,
            'whatsapp_session_id' => $session->id,
            'direction' => 'inbound',
            'type' => 'chat',
            'body' => 'Да, запишите на 13:00, предупредите за 2 часа',
            'ack' => 'delivered',
            'message_timestamp' => now(),
        ]);

        $booking = app(AppointmentBookingService::class)->book(
            $chat,
            $employee,
            $trigger,
            'Массаж',
            Carbon::parse('2026-05-13T13:00:00+05:00'),
            60,
            null,
            null,
            120,
        );

        $this->assertNotNull($booking['reminder']);
        $this->assertTrue($booking['reminder']->scheduled_at->equalTo(Carbon::parse('2026-05-13T11:00:00+05:00')));
    }

    public function test_ai_does_not_book_without_explicit_service_date_and_time_confirmation(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-13 10:00:00', 'Asia/Almaty'));
        config()->set('app.timezone', 'Asia/Almaty');
        config()->set('services.openai.api_key', 'test-key');
        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [
                    ['message' => ['content' => json_encode([
                        'is_appointment_request' => true,
                        'has_explicit_confirmation' => false,
                        'service_name' => 'Массаж',
                        'starts_at' => null,
                        'duration_minutes' => 60,
                        'missing_fields' => ['time'],
                        'client_reply' => 'Подскажите, пожалуйста, на какое время вас записать?',
                        'client_note' => null,
                    ], JSON_THROW_ON_ERROR)]],
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
            'body' => 'Хочу записаться завтра на массаж',
            'ack' => 'delivered',
            'message_timestamp' => now(),
        ]);

        $job = new GenerateAiReplyJob($chat->id, $trigger->id);
        $this->app->call([$job, 'handle']);

        $this->assertDatabaseCount('calendar_events', 0);
        $this->assertDatabaseCount('scheduled_messages', 0);

        $message = Message::query()->where('chat_id', $chat->id)->where('direction', 'outbound')->latest('id')->first();
        $this->assertNotNull($message);
        $this->assertStringContainsString('на какое время', (string) $message->body);
        $this->assertSame('needs_more_details', data_get($message->metadata, 'appointment.status'));
    }

    public function test_ai_hands_off_to_operator_when_requested_time_conflicts(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-13 10:00:00', 'Asia/Almaty'));
        config()->set('app.timezone', 'Asia/Almaty');
        config()->set('services.openai.api_key', 'test-key');
        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [
                    ['message' => ['content' => json_encode([
                        'is_appointment_request' => true,
                        'has_explicit_confirmation' => true,
                        'service_name' => 'Массаж',
                        'starts_at' => '2026-05-14T13:00:00+05:00',
                        'duration_minutes' => 60,
                        'missing_fields' => [],
                        'client_reply' => 'Записала вас на массаж завтра в 13:00.',
                        'client_note' => null,
                    ], JSON_THROW_ON_ERROR)]],
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
        $conflict = CalendarEvent::create([
            'user_id' => $employee->id,
            'assignee_user_id' => $employee->id,
            'title' => 'Existing appointment',
            'starts_at' => Carbon::parse('2026-05-14T12:30:00+05:00'),
            'ends_at' => Carbon::parse('2026-05-14T13:30:00+05:00'),
            'all_day' => false,
        ]);
        $trigger = Message::create([
            'chat_id' => $chat->id,
            'whatsapp_session_id' => $session->id,
            'direction' => 'inbound',
            'type' => 'chat',
            'body' => 'Да, запишите меня завтра на 13:00 на массаж',
            'ack' => 'delivered',
            'message_timestamp' => now(),
        ]);

        $job = new GenerateAiReplyJob($chat->id, $trigger->id);
        $this->app->call([$job, 'handle']);

        $this->assertSame(1, CalendarEvent::query()->count());
        $this->assertDatabaseMissing('calendar_events', [
            'trigger_message_id' => $trigger->id,
            'source' => CalendarEvent::SOURCE_AI_AUTO,
        ]);
        $this->assertDatabaseCount('scheduled_messages', 0);

        $message = Message::query()->where('chat_id', $chat->id)->where('direction', 'outbound')->latest('id')->first();
        $this->assertNotNull($message);
        $this->assertStringContainsString('передам оператору', mb_strtolower((string) $message->body));
        $this->assertSame('conflict', data_get($message->metadata, 'appointment.status'));
        $this->assertSame($conflict->id, data_get($message->metadata, 'appointment.conflict_event_id'));
    }

    public function test_appointment_reminder_is_sent_by_existing_scheduled_message_command(): void
    {
        Bus::fake([SendOutboundMessageJob::class]);

        $company = Company::create(['name' => 'Company']);
        $employee = User::factory()->create(['company_id' => $company->id]);
        $session = WhatsappSession::factory()->create();
        $chat = Chat::factory()->create([
            'company_id' => $company->id,
            'whatsapp_session_id' => $session->id,
        ]);
        $event = CalendarEvent::create([
            'user_id' => $employee->id,
            'assignee_user_id' => $employee->id,
            'chat_id' => $chat->id,
            'title' => 'Массаж',
            'starts_at' => now()->addHour(),
            'ends_at' => now()->addHours(2),
            'all_day' => false,
            'source' => CalendarEvent::SOURCE_AI_AUTO,
        ]);
        $scheduled = ScheduledMessage::create([
            'chat_id' => $chat->id,
            'whatsapp_session_id' => $session->id,
            'user_id' => $employee->id,
            'calendar_event_id' => $event->id,
            'purpose' => ScheduledMessage::PURPOSE_APPOINTMENT_REMINDER,
            'body' => 'Напоминаем: вы записаны на массаж сегодня в 13:00.',
            'display_body' => 'Напоминаем: вы записаны на массаж сегодня в 13:00.',
            'scheduled_at' => now()->subMinute(),
            'status' => ScheduledMessage::STATUS_PENDING,
        ]);

        $this->artisan('scheduled-messages:send')->assertExitCode(0);

        $scheduled->refresh();
        $this->assertSame(ScheduledMessage::STATUS_SENT, $scheduled->status);
        $this->assertNotNull($scheduled->sent_message_id);
        $this->assertDatabaseHas('messages', [
            'id' => $scheduled->sent_message_id,
            'chat_id' => $chat->id,
            'direction' => 'outbound',
        ]);
        Bus::assertDispatched(SendOutboundMessageJob::class);
    }

    public function test_appointment_intent_analysis_triggers_for_window_measurement_booking(): void
    {
        $svc = app(AiAppointmentIntentService::class);
        $msg = new Message([
            'body' => 'Да, жду замер окон завтра в 15:00',
        ]);

        $this->assertTrue($svc->shouldAnalyze($msg));
    }
}
