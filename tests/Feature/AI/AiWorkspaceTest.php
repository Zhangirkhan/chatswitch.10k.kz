<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\Models\Chat;
use App\Models\Contact;
use App\Models\Message;
use App\Models\MessageMedia;
use App\Models\User;
use App\Models\WhatsappSession;
use App\Services\AI\AiWorkspaceSearchService;
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

        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'intent' => 'search_contacts',
                            'reply' => '',
                            'contact_filters' => ['text' => 'Иван'],
                            'media_filters' => ['mime_category' => 'any'],
                        ], JSON_UNESCAPED_UNICODE),
                    ],
                ]],
            ], 200),
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
}
