<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Models\Chat;
use App\Models\User;
use App\Models\WhatsappSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class ChatDraftTranslationApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['administrator', 'manager', 'employee'] as $roleName) {
            Role::findOrCreate($roleName);
        }
    }

    public function test_translate_draft_endpoint_is_registered(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('administrator');

        $session = WhatsappSession::factory()->create();
        $chat = Chat::factory()->create(['whatsapp_session_id' => $session->id]);

        Sanctum::actingAs($admin);

        $response = $this->postJson("/api/v1/chats/{$chat->id}/translate-draft", [
            'text' => 'привет',
            'lang' => 'kk',
        ]);

        $this->assertNotContains($response->status(), [404, 405]);
        $response->assertJsonStructure(['translation', 'target_lang', 'target_label', 'unchanged']);
    }

    public function test_translate_draft_returns_unchanged_when_text_already_matches_lang(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('administrator');

        $session = WhatsappSession::factory()->create();
        $chat = Chat::factory()->create(['whatsapp_session_id' => $session->id]);

        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/chats/{$chat->id}/translate-draft", [
            'text' => 'Здравствуйте',
            'lang' => 'ru',
        ])
            ->assertOk()
            ->assertJsonPath('translation', 'Здравствуйте')
            ->assertJsonPath('unchanged', true);
    }

    public function test_employee_without_chat_access_gets_forbidden(): void
    {
        $user = User::factory()->create();
        $user->assignRole('employee');

        $session = WhatsappSession::factory()->create();
        $chat = Chat::factory()->create(['whatsapp_session_id' => $session->id]);

        Sanctum::actingAs($user);

        $this->postJson("/api/v1/chats/{$chat->id}/translate-draft", [
            'text' => 'привет',
            'lang' => 'kk',
        ])->assertForbidden();
    }
}
