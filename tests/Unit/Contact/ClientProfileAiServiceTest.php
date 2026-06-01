<?php

declare(strict_types=1);

namespace Tests\Unit\Contact;

use App\Models\Chat;
use App\Models\Contact;
use App\Models\User;
use App\Models\WhatsappSession;
use App\Services\Contact\ClientProfileAiService;
use App\Services\Contact\ClientProfileAssembler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class ClientProfileAiServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['administrator', 'manager', 'employee'] as $role) {
            Role::findOrCreate($role);
        }
    }

    public function test_ai_service_does_not_fill_finance_section(): void
    {
        config(['services.openai.api_key' => 'test-key']);

        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'basic' => [['label' => 'Источник', 'value' => 'Instagram', 'source' => 'chat']],
                            'contacts' => [],
                            'b2b' => [],
                            'history' => [],
                            'tasks_notes' => [],
                        ], JSON_UNESCAPED_UNICODE),
                    ],
                ]],
            ], 200),
        ]);

        $admin = User::factory()->create();
        $admin->assignRole('administrator');
        $session = WhatsappSession::factory()->create();
        $contact = Contact::factory()->create(['name' => 'Клиент']);
        Chat::factory()->create([
            'contact_id' => $contact->id,
            'whatsapp_session_id' => $session->id,
            'is_group' => false,
        ]);

        $assembler = app(ClientProfileAssembler::class);
        $ai = app(ClientProfileAiService::class);

        $profile = $assembler->build($admin, $contact);
        $enriched = $ai->enrich($admin, $contact, $profile);

        $finance = collect($enriched['sections'])->firstWhere('key', 'finance');
        $this->assertSame('unavailable', $finance['status'] ?? null);
        $this->assertSame([], $finance['fields'] ?? null);

        $basic = collect($enriched['sections'])->firstWhere('key', 'basic');
        $this->assertTrue(
            collect($basic['fields'] ?? [])
                ->contains(fn (array $field): bool => ($field['label'] ?? '') === 'Источник'),
        );
    }
}
