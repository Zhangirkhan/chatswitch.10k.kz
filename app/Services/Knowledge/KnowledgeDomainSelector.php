<?php

declare(strict_types=1);

namespace App\Services\Knowledge;

use App\Models\Chat;

/**
 * Maps the current conversation context to a knowledge domain tag.
 *
 * Domains correspond to the `knowledge_chunks.domain` column.
 * When a domain is detected the RAG retriever can boost or pre-filter
 * relevant chunks so topic-specific queries work better.
 *
 * Domain detection priority:
 *  1. Active topic stored on the chat (set by ActiveTopicDetector, highest fidelity)
 *  2. Raw query keyword scan (fallback for chats without topic yet)
 */
final class KnowledgeDomainSelector
{
    /**
     * Domain constants — kept in sync with KnowledgeChunkFactory tagging.
     */
    public const DOMAIN_DELIVERY  = 'delivery';
    public const DOMAIN_PAYMENT   = 'payment';
    public const DOMAIN_PRICE     = 'price';
    public const DOMAIN_CATALOG   = 'catalog';
    public const DOMAIN_WARRANTY  = 'warranty';
    public const DOMAIN_SCHEDULE  = 'schedule';

    /**
     * Keyword → domain map (checked with str_contains on lowercased string).
     *
     * @var array<string, list<string>>
     */
    private const KEYWORDS = [
        self::DOMAIN_DELIVERY => [
            'достав', 'курьер', 'отгруз', 'отправ', 'монтаж', 'установк',
            'алматы', 'астана', 'шымкент', 'нур-султ', 'получить заказ',
        ],
        self::DOMAIN_PAYMENT  => [
            'оплат', 'счёт', 'счет', 'предоплат', 'перевод', 'каспи',
            'реквизит', 'kaspi', 'kaspi pay',
        ],
        self::DOMAIN_PRICE    => [
            'цен', 'сколько стоит', 'прайс', 'стоимост', 'скидк', 'акци',
        ],
        self::DOMAIN_WARRANTY => [
            'гарант', 'ремонт', 'возврат', 'замен', 'брак', 'дефект',
        ],
        self::DOMAIN_CATALOG  => [
            'ассортимент', 'каталог', 'что есть', 'что у вас',
            'какие товар', 'какие услуг', 'что продаёт', 'что продаете',
        ],
        self::DOMAIN_SCHEDULE => [
            'запис', 'расписани', 'свободн', 'слот', 'замер', 'выезд',
            'когда можн', 'когда приедет',
        ],
    ];

    /**
     * Detect the knowledge domain from the enriched query string and chat context.
     *
     * Returns null when no domain can be confidently identified (retrieval
     * should then run without domain filtering).
     */
    public function detect(string $query, Chat $chat): ?string
    {
        // Prefer the active topic which was already domain-tagged by ActiveTopicDetector.
        $activeTopic = (string) ($chat->active_topic ?? '');
        if ($activeTopic !== '') {
            $domain = $this->extractDomainHint($activeTopic)
                ?? $this->scanKeywords(mb_strtolower($activeTopic));
            if ($domain !== null) {
                return $domain;
            }
        }

        // Fallback: scan the raw query.
        return $this->scanKeywords(mb_strtolower($query));
    }

    /**
     * Extract the domain hint embedded in topic strings like "[delivery] доставка…".
     */
    private function extractDomainHint(string $topic): ?string
    {
        if (preg_match('/^\[([a-z_]+)\]/u', $topic, $m)) {
            $hint = $m[1];
            $valid = [
                self::DOMAIN_DELIVERY, self::DOMAIN_PAYMENT, self::DOMAIN_PRICE,
                self::DOMAIN_CATALOG, self::DOMAIN_WARRANTY, self::DOMAIN_SCHEDULE,
            ];

            return in_array($hint, $valid, true) ? $hint : null;
        }

        return null;
    }

    /**
     * Scan lowercased text for domain keywords, returning the first match.
     */
    private function scanKeywords(string $lower): ?string
    {
        foreach (self::KEYWORDS as $domain => $keywords) {
            foreach ($keywords as $kw) {
                if (str_contains($lower, $kw)) {
                    return $domain;
                }
            }
        }

        return null;
    }
}
