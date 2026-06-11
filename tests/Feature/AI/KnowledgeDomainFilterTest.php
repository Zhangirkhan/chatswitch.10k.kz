<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\Models\Chat;
use App\Services\Knowledge\KnowledgeDomainSelector;
use Tests\TestCase;

/**
 * Tests for KnowledgeDomainSelector domain detection.
 */
final class KnowledgeDomainFilterTest extends TestCase
{
    private KnowledgeDomainSelector $selector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->selector = new KnowledgeDomainSelector();
    }

    public function test_delivery_detected_from_query(): void
    {
        $chat = $this->makeChat();
        $domain = $this->selector->detect('когда будет доставка по Алматы?', $chat);
        $this->assertSame(KnowledgeDomainSelector::DOMAIN_DELIVERY, $domain);
    }

    public function test_payment_detected_from_query(): void
    {
        $chat = $this->makeChat();
        $domain = $this->selector->detect('как оплатить через каспи?', $chat);
        $this->assertSame(KnowledgeDomainSelector::DOMAIN_PAYMENT, $domain);
    }

    public function test_price_detected_from_query(): void
    {
        $chat = $this->makeChat();
        $domain = $this->selector->detect('сколько стоит диван?', $chat);
        $this->assertSame(KnowledgeDomainSelector::DOMAIN_PRICE, $domain);
    }

    public function test_active_topic_hint_overrides_query(): void
    {
        // Active topic has domain hint; query has a different keyword.
        $chat = $this->makeChat('[payment] оплата каспи');
        $domain = $this->selector->detect('доставка', $chat);

        // Topic hint should win.
        $this->assertSame(KnowledgeDomainSelector::DOMAIN_PAYMENT, $domain);
    }

    public function test_no_domain_returns_null(): void
    {
        $chat = $this->makeChat();
        $domain = $this->selector->detect('Привет', $chat);
        $this->assertNull($domain);
    }

    public function test_delivery_keyword_in_active_topic_detected(): void
    {
        $chat = $this->makeChat('доставка по Астане, мебель');
        $domain = $this->selector->detect('уточнили?', $chat);
        $this->assertSame(KnowledgeDomainSelector::DOMAIN_DELIVERY, $domain);
    }

    /** @param  string|null  $activeTopic */
    private function makeChat(?string $activeTopic = null): Chat
    {
        $chat = new Chat();
        $chat->id = 1;
        $chat->company_id = 1;
        $chat->active_topic = $activeTopic;

        return $chat;
    }
}
