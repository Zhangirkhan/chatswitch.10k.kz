<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\Models\CalendarEvent;
use App\Models\Chat;
use App\Models\Contact;
use App\Models\Department;
use App\Models\Message;
use App\Models\MessageMedia;
use App\Models\User;
use App\Models\WhatsappSession;
use App\Services\AI\AiWorkspaceSearchService;
use App\Support\TenantCompany;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class AiWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['administrator', 'manager', 'employee'] as $role) {
            Role::findOrCreate($role);
        }
    }

    /**
     * @param  array<string, mixed>  $parsePayload
     * @param  array<string, mixed>|null  $summaryPayload
     */
    private function fakeOpenAiWorkspace(
        array $parsePayload,
        string $answer = 'Ответ на основе данных.',
        ?array $summaryPayload = null,
    ): void {
        $sequence = Http::sequence()
            ->push([
                'choices' => [[
                    'message' => [
                        'content' => json_encode($parsePayload, JSON_UNESCAPED_UNICODE),
                    ],
                ]],
            ], 200)
            ->push([
                'choices' => [[
                    'message' => ['content' => $answer],
                ]],
            ], 200);

        if ($summaryPayload !== null) {
            $sequence->push([
                'choices' => [[
                    'message' => [
                        'content' => json_encode($summaryPayload, JSON_UNESCAPED_UNICODE),
                    ],
                ]],
            ], 200);
        }

        Http::fake([
            'https://api.openai.com/v1/chat/completions' => $sequence,
        ]);
    }

    public function test_ai_chat_page_is_available_for_authenticated_user(): void
    {
        $user = User::factory()->create();
        $user->assignRole('employee');

        $this->actingAs($user)
            ->get(route('ai-chat.index'))
            ->assertOk();
    }

    public function test_workspace_query_returns_contacts_from_search_service(): void
    {
        config(['services.openai.api_key' => 'test-key']);

        $this->fakeOpenAiWorkspace([
            'intent' => 'search_contacts',
            'reply' => '',
            'contact_filters' => ['text' => 'Иван'],
            'media_filters' => ['mime_category' => 'any'],
        ], 'Найден контакт.', [
            'headline' => 'Клиент Иван',
            'sections' => [
                ['title' => 'Кто это', 'body' => 'Иван Тестов'],
            ],
            'confidence' => 'medium',
        ]);

        $user = User::factory()->create();
        $user->assignRole('administrator');
        $session = WhatsappSession::factory()->create();
        $contact = Contact::factory()->create(['name' => 'Иван Тестов']);
        $chat = Chat::factory()->create([
            'contact_id' => $contact->id,
            'whatsapp_session_id' => $session->id,
            'is_group' => false,
        ]);

        $response = $this->actingAs($user)->postJson(route('ai-chat.query'), [
            'message' => 'найди Ивана',
        ]);

        $response->assertOk();
        $response->assertJsonPath('intent', 'search_contacts');
        $this->assertNotEmpty($response->json('contacts'));
        $this->assertSame($contact->id, $response->json('contacts.0.id'));
        $this->assertSame($chat->id, $response->json('contacts.0.chat_id'));
        $this->assertIsArray($response->json('visualizations'));
        $this->assertSame('Клиент Иван', $response->json('client_summary.ai.headline'));
    }

    public function test_workspace_query_client_profile_returns_client_summary(): void
    {
        config(['services.openai.api_key' => 'test-key']);

        $this->fakeOpenAiWorkspace([
            'intent' => 'client_profile',
            'reply' => 'Кратко о клиенте.',
            'contact_filters' => ['text' => 'Айдар'],
            'primary_contact_id' => null,
        ], 'Сводка готова.', [
            'headline' => 'Активный клиент',
            'sections' => [
                ['title' => 'Предпочтения', 'body' => 'Пишет по будням'],
            ],
            'confidence' => 'high',
        ]);

        $user = User::factory()->create();
        $user->assignRole('administrator');
        $session = WhatsappSession::factory()->create();
        $contact = Contact::factory()->create(['name' => 'Айдар']);
        Chat::factory()->create([
            'contact_id' => $contact->id,
            'whatsapp_session_id' => $session->id,
            'is_group' => false,
        ]);

        $response = $this->actingAs($user)->postJson(route('ai-chat.query'), [
            'message' => 'Расскажи про клиента Айдар',
        ]);

        $response->assertOk();
        $response->assertJsonPath('intent', 'client_profile');
        $response->assertJsonPath('client_summary.contact_id', $contact->id);
        $response->assertJsonPath('client_summary.ai.headline', 'Активный клиент');
    }

    public function test_workspace_query_returns_visualizations_for_chart_request(): void
    {
        config(['services.openai.api_key' => 'test-key']);

        $this->fakeOpenAiWorkspace([
            'intent' => 'search_media',
            'reply' => '',
            'contact_filters' => ['limit' => 25],
            'media_filters' => ['mime_category' => 'document'],
            'visualizations' => [],
        ]);

        $user = User::factory()->create();
        $user->assignRole('administrator');
        $session = WhatsappSession::factory()->create();
        $chat = Chat::factory()->create([
            'whatsapp_session_id' => $session->id,
            'is_group' => false,
        ]);
        $message = Message::create([
            'chat_id' => $chat->id,
            'whatsapp_session_id' => $session->id,
            'direction' => 'inbound',
            'type' => 'document',
            'body' => '',
            'ack' => 3,
        ]);
        MessageMedia::query()->create([
            'message_id' => $message->id,
            'mime_type' => 'application/pdf',
            'filename' => 'invoice.pdf',
            'disk_path' => 'media/invoice.pdf',
            'file_size' => 100,
        ]);

        $response = $this->actingAs($user)->postJson(route('ai-chat.query'), [
            'message' => 'найди pdf и построй график по типам файлов',
        ]);

        $response->assertOk();
        $this->assertNotEmpty($response->json('visualizations'));
        $this->assertSame('chart', $response->json('visualizations.0.type'));
    }

    public function test_search_media_respects_visible_chats(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('administrator');
        $employee = User::factory()->create();
        $employee->assignRole('employee');

        $session = WhatsappSession::factory()->create();
        $contact = Contact::factory()->create(['name' => 'Media Client']);
        $chat = Chat::factory()->create([
            'contact_id' => $contact->id,
            'whatsapp_session_id' => $session->id,
            'is_group' => false,
        ]);
        $message = Message::create([
            'chat_id' => $chat->id,
            'whatsapp_session_id' => $session->id,
            'direction' => 'inbound',
            'type' => 'document',
            'body' => '',
            'ack' => 3,
        ]);
        MessageMedia::query()->create([
            'message_id' => $message->id,
            'mime_type' => 'application/pdf',
            'filename' => 'contract-may.pdf',
            'disk_path' => 'media/test.pdf',
            'file_size' => 100,
        ]);

        $otherChat = Chat::factory()->create([
            'whatsapp_session_id' => $session->id,
            'is_group' => false,
        ]);
        $otherMessage = Message::create([
            'chat_id' => $otherChat->id,
            'whatsapp_session_id' => $session->id,
            'direction' => 'inbound',
            'type' => 'document',
            'body' => '',
            'ack' => 3,
        ]);
        MessageMedia::query()->create([
            'message_id' => $otherMessage->id,
            'mime_type' => 'application/pdf',
            'filename' => 'secret.pdf',
            'disk_path' => 'media/secret.pdf',
            'file_size' => 50,
        ]);

        $service = app(AiWorkspaceSearchService::class);
        $found = $service->searchMedia($admin, ['filename_contains' => 'pdf']);
        $this->assertCount(2, $found);

        $employeeFound = $service->searchMedia($employee, ['filename_contains' => 'pdf']);
        $this->assertCount(0, $employeeFound);
    }

    public function test_calendar_search_denies_employee_access_to_colleague_schedule(): void
    {
        config(['services.openai.api_key' => 'test-key']);

        $dept = Department::query()->create(['name' => 'Продажи', 'is_active' => true]);

        $employee = User::factory()->create(['company_id' => TenantCompany::id()]);
        $employee->assignRole('employee');
        $employee->departments()->sync([$dept->id]);

        $colleague = User::factory()->create(['company_id' => TenantCompany::id(), 'name' => 'Михаил']);
        $colleague->assignRole('employee');
        $colleague->departments()->sync([$dept->id]);

        CalendarEvent::query()->create([
            'user_id' => $colleague->id,
            'assignee_user_id' => $colleague->id,
            'title' => 'Встреча',
            'starts_at' => Carbon::now()->addDay()->setTime(10, 0),
            'ends_at' => Carbon::now()->addDay()->setTime(11, 0),
            'all_day' => false,
        ]);

        $this->fakeOpenAiWorkspace([
            'intent' => 'search_calendar',
            'reply' => '',
            'calendar_filters' => ['employee_name' => 'Михаил', 'days_ahead' => 7],
        ], 'Нет доступа к календарю коллеги.');

        $response = $this->actingAs($employee)->postJson(route('ai-chat.query'), [
            'message' => 'Когда занят Михаил на этой неделе?',
        ]);

        $response->assertOk();
        $this->assertSame([], $response->json('calendar_events'));
        $this->assertTrue($response->json('calendar_meta.not_found') === true || $response->json('calendar_meta.access_denied') === true);
    }

    public function test_manager_can_search_colleague_calendar_events(): void
    {
        config(['services.openai.api_key' => 'test-key']);

        $dept = Department::query()->create(['name' => 'Продажи', 'is_active' => true]);

        $manager = User::factory()->create(['company_id' => TenantCompany::id()]);
        $manager->assignRole('manager');
        $manager->departments()->sync([$dept->id]);

        $mikhail = User::factory()->create(['company_id' => TenantCompany::id(), 'name' => 'Михаил Иванов']);
        $mikhail->assignRole('employee');
        $mikhail->departments()->sync([$dept->id]);

        CalendarEvent::query()->create([
            'user_id' => $mikhail->id,
            'assignee_user_id' => $mikhail->id,
            'title' => 'Запись клиента',
            'starts_at' => Carbon::now()->addDays(2)->setTime(14, 0),
            'ends_at' => Carbon::now()->addDays(2)->setTime(15, 0),
            'all_day' => false,
        ]);

        $this->fakeOpenAiWorkspace([
            'intent' => 'search_calendar',
            'reply' => '',
            'calendar_filters' => ['employee_name' => 'Михаил', 'days_ahead' => 14],
        ], 'У Михаила есть запись «Запись клиента».');

        $response = $this->actingAs($manager)->postJson(route('ai-chat.query'), [
            'message' => 'Какие записи у Михаила на две недели?',
        ]);

        $response->assertOk();
        $this->assertNotEmpty($response->json('calendar_events'));
        $this->assertSame('Запись клиента', $response->json('calendar_events.0.title'));
    }

    public function test_calendar_search_includes_past_events_by_default(): void
    {
        config(['services.openai.api_key' => 'test-key']);

        $admin = User::factory()->create(['company_id' => TenantCompany::id()]);
        $admin->assignRole('administrator');

        CalendarEvent::query()->create([
            'user_id' => $admin->id,
            'assignee_user_id' => $admin->id,
            'title' => 'Визит вчера',
            'starts_at' => Carbon::now()->subDays(2)->setTime(11, 0),
            'ends_at' => Carbon::now()->subDays(2)->setTime(12, 0),
            'all_day' => false,
        ]);

        $service = app(AiWorkspaceSearchService::class);
        $bundle = $service->searchCalendarEvents($admin, ['days_ahead' => 7]);

        $this->assertNotEmpty($bundle['events']);
        $this->assertSame('Визит вчера', $bundle['events'][0]['title']);
        $this->assertTrue(
            Carbon::parse((string) $bundle['meta']['date_from'])->lt(Carbon::now()->startOfDay()),
        );
    }
}
