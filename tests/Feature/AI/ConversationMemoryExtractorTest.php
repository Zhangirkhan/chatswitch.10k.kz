<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\Enums\EntityMemorySubjectType;
use App\Models\Chat;
use App\Models\Contact;
use App\Models\Message;
use App\Services\AI\AiCrmWritebackService;
use App\Services\AI\AiUsageOptions;
use App\Services\AI\ConversationMemoryExtractor;
use App\Services\AI\OpenAiChatService;
use App\Services\Memory\EntityMemoryService;
use App\Support\AiFeatureFlags;
use App\Support\TenantCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class ConversationMemoryExtractorTest extends TestCase
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
        config(['entity-memory.disk' => 'local', 'ai.memory_extraction_max_tokens' => 800]);
    }

    public function test_extractor_writes_facts_to_contact_memory(): void
    {
        $contact = Contact::factory()->create(['company_id' => TenantCompany::id()]);
        $chat = Chat::factory()->create([
            'company_id' => TenantCompany::id(),
            'contact_id' => $contact->id,
        ]);
        Message::factory()->create([
            'chat_id' => $chat->id,
            'direction' => 'inbound',
            'body' => 'Мне нужен диван, бюджет около 150 000 тенге',
            'message_timestamp' => now()->subMinutes(5),
        ]);
        Message::factory()->create([
            'chat_id' => $chat->id,
            'direction' => 'outbound',
            'body' => 'Отлично, покажу варианты в этом диапазоне.',
            'message_timestamp' => now()->subMinutes(4),
        ]);

        $fakeFacts = [
            'budget' => '150 000 тенге',
            'requirements' => 'диван',
        ];

        $openAiMock = $this->createMock(OpenAiChatService::class);
        $openAiMock->method('chatJson')->willReturn($fakeFacts);

        $extractor = new ConversationMemoryExtractor(
            $openAiMock,
            app(EntityMemoryService::class),
        );

        $facts = $extractor->extractFacts($chat);
        $extractor->persistFacts($chat, $facts);

        $memory = app(EntityMemoryService::class)->get(EntityMemorySubjectType::Contact, $contact->id);
        $this->assertStringContainsString('AI-факты (авто)', $memory->content);
        $this->assertStringContainsString('150 000 тенге', $memory->content);
    }

    public function test_extractor_is_noop_when_chat_has_no_contact(): void
    {
        $chat = Chat::factory()->create([
            'company_id' => TenantCompany::id(),
            'contact_id' => null,
        ]);

        $openAiMock = $this->createMock(OpenAiChatService::class);
        $openAiMock->expects($this->never())->method('chatJson');

        $extractor = new ConversationMemoryExtractor($openAiMock, app(EntityMemoryService::class));

        $this->assertSame([], $extractor->extractFacts($chat));
    }

    public function test_crm_writeback_creates_ai_tags(): void
    {
        $contact = Contact::factory()->create(['company_id' => TenantCompany::id()]);
        $chat = Chat::factory()->create([
            'company_id' => TenantCompany::id(),
            'contact_id' => $contact->id,
        ]);

        // Enable the flag for this company.
        AiFeatureFlags::enable(AiFeatureFlags::CRM_WRITEBACK, TenantCompany::id());

        $service = app(AiCrmWritebackService::class);
        $service->writeContactEnrichment($chat, [
            'budget' => '150 000 тенге',
            'source' => 'Instagram',
        ]);

        $this->assertDatabaseHas('contact_tags', [
            'contact_id' => $contact->id,
            'source' => 'ai',
        ]);

        $this->assertNotNull($contact->fresh()->ai_enriched_at);
    }

    public function test_crm_writeback_is_noop_when_flag_disabled(): void
    {
        $contact = Contact::factory()->create(['company_id' => TenantCompany::id()]);
        $chat = Chat::factory()->create([
            'company_id' => TenantCompany::id(),
            'contact_id' => $contact->id,
        ]);

        // Flag intentionally NOT enabled.

        $service = app(AiCrmWritebackService::class);
        $service->writeContactEnrichment($chat, ['budget' => '100 000']);

        $this->assertDatabaseMissing('contact_tags', ['contact_id' => $contact->id]);
    }
}
