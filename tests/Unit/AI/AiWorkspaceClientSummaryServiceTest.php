<?php

declare(strict_types=1);

namespace Tests\Unit\AI;

use App\Models\Chat;
use App\Models\Contact;
use App\Models\User;
use App\Models\WhatsappSession;
use App\Services\AI\AiWorkspaceClientSummaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class AiWorkspaceClientSummaryServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['administrator', 'manager', 'employee'] as $role) {
            Role::findOrCreate($role);
        }
    }

    public function test_build_merges_crm_context_and_ai_sections(): void
    {
        config(['services.openai.api_key' => 'test-key']);

        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'headline' => 'Клиент интересуется услугой',
                            'sections' => [
                                ['title' => 'Договорённости', 'body' => 'Ждёт КП до пятницы'],
                            ],
                            'confidence' => 'high',
                        ], JSON_UNESCAPED_UNICODE),
                    ],
                ]],
            ], 200),
        ]);

        $admin = User::factory()->create();
        $admin->assignRole('administrator');
        $session = WhatsappSession::factory()->create();
        $contact = Contact::factory()->create([
            'name' => 'Марина',
            'phone_number' => '77005554433',
        ]);
        $chat = Chat::factory()->create([
            'contact_id' => $contact->id,
            'whatsapp_session_id' => $session->id,
            'chat_name' => 'Марина',
            'is_group' => false,
            'ai_orchestrator_last_summary' => 'Просит прайс на пятницу.',
        ]);

        $summary = app(AiWorkspaceClientSummaryService::class)->build($admin, $contact, $chat->id);

        $this->assertNotNull($summary);
        $this->assertSame($contact->id, $summary['contact_id']);
        $this->assertSame('Марина', $summary['identity']['display_name']);
        $this->assertSame($chat->id, $summary['primary_chat_id']);
        $this->assertSame('Клиент интересуется услугой', $summary['ai']['headline']);
        $this->assertSame('high', $summary['ai']['confidence']);
        $this->assertNotEmpty($summary['ai']['sections']);
    }
}
