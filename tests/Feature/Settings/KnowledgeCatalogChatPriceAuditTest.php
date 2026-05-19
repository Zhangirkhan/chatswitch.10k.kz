<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Models\Chat;
use App\Models\Message;
use App\Models\Product;
use App\Models\WhatsappSession;
use App\Services\Knowledge\KnowledgeCatalogChatPriceAuditService;
use App\Support\TenantCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class KnowledgeCatalogChatPriceAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_detects_price_in_chat_different_from_catalog(): void
    {
        TenantCompany::ensureExists();
        $companyId = TenantCompany::id();
        $session = WhatsappSession::factory()->create();
        $chat = Chat::factory()->create([
            'company_id' => $companyId,
            'whatsapp_session_id' => $session->id,
        ]);

        Product::query()->create([
            'company_id' => $companyId,
            'name' => 'Диван Milano',
            'price' => 150_000,
            'include_in_prompt' => true,
            'is_active' => true,
        ]);

        Message::query()->create([
            'chat_id' => $chat->id,
            'whatsapp_session_id' => $session->id,
            'direction' => 'outbound',
            'type' => 'text',
            'body' => 'Диван Milano сейчас 195 000 ₸ с доставкой.',
            'message_timestamp' => now()->subDay(),
        ]);

        $findings = app(KnowledgeCatalogChatPriceAuditService::class)->audit($companyId);

        $this->assertNotEmpty($findings);
        $this->assertStringContainsString('chat_price_mismatch', $findings[0]['key']);
    }
}
