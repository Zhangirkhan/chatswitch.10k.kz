<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\Models\Chat;
use App\Models\Contact;
use App\Models\Message;
use App\Models\User;
use App\Services\AI\PromptBuilder;
use App\Support\AiFeatureFlags;
use App\Support\TenantCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class PromptBuilderHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['administrator', 'manager', 'employee'] as $role) {
            Role::findOrCreate($role);
        }
        TenantCompany::ensureExists();
        Storage::fake('local');
        config(['entity-memory.disk' => 'local']);
    }

    /**
     * C2: AI-generated replies should appear in history when flag is on.
     */
    public function test_ai_replies_included_in_history_when_flag_enabled(): void
    {
        AiFeatureFlags::enable(AiFeatureFlags::HISTORY_INCLUDES_AI_REPLIES, TenantCompany::id());

        $responder = User::factory()->create(['company_id' => TenantCompany::id()]);
        $chat = Chat::factory()->create(['company_id' => TenantCompany::id()]);

        Message::factory()->create([
            'chat_id' => $chat->id,
            'direction' => 'inbound',
            'body' => 'Какая цена?',
            'message_timestamp' => now()->subMinutes(3),
        ]);
        Message::factory()->create([
            'chat_id' => $chat->id,
            'direction' => 'outbound',
            'body' => 'Цена 50 000 тенге',
            'message_timestamp' => now()->subMinutes(2),
            'metadata' => ['ai' => ['generated' => true]],
        ]);

        $builder = app(PromptBuilder::class);
        $built = $builder->build($chat, $responder, 'Есть ли скидки?', TenantCompany::id());

        $messages = $built['messages'];

        // Should have at least one 'assistant' turn from the AI-generated reply.
        $assistantTurns = array_filter($messages, fn ($m) => $m['role'] === 'assistant');
        $this->assertNotEmpty($assistantTurns, 'Expected assistant turn from AI-generated reply');

        // The AI reply body should appear in the conversation.
        $historyContent = implode(' ', array_column(array_values($assistantTurns), 'content'));
        $this->assertStringContainsString('50 000', $historyContent);
    }

    /**
     * C2: AI-generated replies should be stripped in legacy mode (flag off).
     */
    public function test_ai_replies_excluded_in_legacy_mode(): void
    {
        // Flag intentionally NOT enabled.

        $responder = User::factory()->create(['company_id' => TenantCompany::id()]);
        $chat = Chat::factory()->create(['company_id' => TenantCompany::id()]);

        Message::factory()->create([
            'chat_id' => $chat->id,
            'direction' => 'outbound',
            'body' => 'Цена 50 000 тенге — ТОЛЬКО ДЛЯ ТЕСТА',
            'message_timestamp' => now()->subMinutes(2),
            'metadata' => ['ai' => ['generated' => true]],
        ]);

        $builder = app(PromptBuilder::class);
        $built = $builder->build($chat, $responder, 'Тест', TenantCompany::id());

        $allContent = implode(' ', array_column($built['messages'], 'content'));
        $this->assertStringNotContainsString('ТОЛЬКО ДЛЯ ТЕСТА', $allContent);
    }

    /**
     * C3: Contact-scoped history should load from multiple chats of same contact.
     */
    public function test_contact_scoped_history_includes_all_contact_chats(): void
    {
        AiFeatureFlags::enable(AiFeatureFlags::HISTORY_CONTACT_SCOPED, TenantCompany::id());

        $responder = User::factory()->create(['company_id' => TenantCompany::id()]);
        $contact = Contact::factory()->create(['company_id' => TenantCompany::id()]);

        $chat1 = Chat::factory()->create([
            'company_id' => TenantCompany::id(),
            'contact_id' => $contact->id,
        ]);
        $chat2 = Chat::factory()->create([
            'company_id' => TenantCompany::id(),
            'contact_id' => $contact->id,
        ]);

        Message::factory()->create([
            'chat_id' => $chat1->id,
            'direction' => 'inbound',
            'body' => 'Сообщение из первого чата',
            'message_timestamp' => now()->subDays(2),
        ]);
        Message::factory()->create([
            'chat_id' => $chat2->id,
            'direction' => 'inbound',
            'body' => 'Сообщение из второго чата',
            'message_timestamp' => now()->subDay(),
        ]);

        $builder = app(PromptBuilder::class);
        $built = $builder->build($chat2, $responder, 'Тест', TenantCompany::id());

        $allContent = implode(' ', array_column($built['messages'], 'content'));
        $this->assertStringContainsString('Сообщение из первого чата', $allContent,
            'Contact-scoped history should include messages from chat1');
    }
}
