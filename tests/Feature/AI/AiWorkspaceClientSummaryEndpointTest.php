<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\Models\Chat;
use App\Models\Contact;
use App\Models\User;
use App\Models\WhatsappSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class AiWorkspaceClientSummaryEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['administrator', 'manager', 'employee'] as $role) {
            Role::findOrCreate($role);
        }
    }

    public function test_client_summary_endpoint_returns_payload_for_authorized_user(): void
    {
        config(['services.openai.api_key' => 'test-key']);

        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'headline' => 'Постоянный клиент',
                            'sections' => [
                                ['title' => 'Кто это', 'body' => 'Покупатель услуг'],
                            ],
                            'confidence' => 'medium',
                        ], JSON_UNESCAPED_UNICODE),
                    ],
                ]],
            ], 200),
        ]);

        $admin = User::factory()->create();
        $admin->assignRole('administrator');
        $session = WhatsappSession::factory()->create();
        $contact = Contact::factory()->create(['name' => 'Серик']);
        Chat::factory()->create([
            'contact_id' => $contact->id,
            'whatsapp_session_id' => $session->id,
            'is_group' => false,
        ]);

        $response = $this->actingAs($admin)->getJson(route('ai-chat.client-summary', $contact));

        $response->assertOk();
        $response->assertJsonPath('client_summary.contact_id', $contact->id);
        $response->assertJsonPath('client_summary.identity.display_name', 'Серик');
        $response->assertJsonPath('client_summary.ai.headline', 'Постоянный клиент');
    }

    public function test_client_summary_endpoint_denies_employee_without_chat_access(): void
    {
        config(['services.openai.api_key' => 'test-key']);

        $employee = User::factory()->create();
        $employee->assignRole('employee');

        $session = WhatsappSession::factory()->create();
        $contact = Contact::factory()->create(['name' => 'Hidden Client']);
        Chat::factory()->create([
            'contact_id' => $contact->id,
            'whatsapp_session_id' => $session->id,
            'is_group' => false,
        ]);

        $this->actingAs($employee)
            ->getJson(route('ai-chat.client-summary', $contact))
            ->assertForbidden();
    }
}
