<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\Chat;
use App\Models\Message;
use App\Support\MessageInboundText;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Detects and persists the active conversation topic on a chat.
 *
 * The topic is a short phrase (≤120 chars) summarising what the client is
 * currently asking about. It is kept "sticky" — only refreshed when the
 * incoming message is substantive (not a vague follow-up like «Уточнили?»).
 *
 * The stored topic is consumed by RetrievalQueryBuilder to enrich the
 * RAG vector query so short/anaphoric follow-ups still find relevant chunks.
 */
final class ActiveTopicDetector
{
    /**
     * Vague short follow-up patterns that should NOT reset the topic.
     * When the trigger matches one of these, the current topic is preserved.
     */
    private const VAGUE_PATTERNS = [
        '/^(уточнили|уточнил|узнали|узнал|нашли|нашёл|нашел|проверили|проверил)[?!.…]?$/ui',
        '/^(ну\s+(что|как)?|и\s+что|и\s+как|а\s+что)[?!.…]?$/ui',
        '/^(а\s+по\s+(тем|этим|тому|этому)).*$/ui',
        '/^(ок|ok|окей|хорошо|понятно|ясно|ладно|давай|пойдёт|пойдет|понял|поняла)[.!,?…]?$/ui',
        '/^(да|нет|наверное|возможно|может\s+быть)[.!,?…]?$/ui',
        '/^(спасибо|благодарю|thanks|thank\s+you)[.!,?…]?$/ui',
        '/^[?!.…]+$/u',
    ];

    /** Max length of the stored topic string (chars). */
    private const MAX_TOPIC_CHARS = 120;

    /**
     * Keyword → topic hint map: when a message contains any of these keywords
     * the topic is extracted / reinforced even if the message is short.
     *
     * @var array<string, list<string>>
     */
    private const DOMAIN_KEYWORDS = [
        'delivery' => ['достав', 'курьер', 'отгруз', 'отправ', 'получить заказ', 'алматы', 'астана', 'шымкент', 'нур-султ', 'монтаж', 'установк'],
        'payment'  => ['оплат', 'счёт', 'счет', 'предоплат', 'перевод', 'каспи', 'реквизит', 'kaspi'],
        'price'    => ['цен', 'сколько стоит', 'прайс', 'стоимост', 'скидк', 'акци'],
        'catalog'  => ['ассортимент', 'каталог', 'что есть', 'что у вас', 'какие товар', 'какие услуг'],
        'warranty' => ['гарант', 'ремонт', 'возврат', 'замен', 'брак'],
    ];

    /**
     * Update the chat's active_topic if the trigger message is substantive.
     * A no-op if the message is a vague follow-up and a topic is already set.
     */
    public function updateFromMessage(Chat $chat, Message $triggerMessage): void
    {
        $body = trim(MessageInboundText::forMessage($triggerMessage));

        if ($body === '') {
            return;
        }

        // If the message is a vague follow-up and we already have a topic, keep it.
        if ($this->isVagueFollowUp($body) && $chat->active_topic !== null) {
            return;
        }

        $topic = $this->extractTopic($body, $chat);

        if ($topic === null || $topic === '') {
            return;
        }

        // Only write when the topic actually changed (avoid unnecessary writes).
        if ($topic === $chat->active_topic) {
            return;
        }

        // Shift the current active_topic into recent_topics (keep up to 2).
        $recent = is_array($chat->recent_topics) ? $chat->recent_topics : [];
        if ($chat->active_topic !== null && $chat->active_topic !== '') {
            array_unshift($recent, $chat->active_topic);
            $recent = array_values(array_unique(array_slice($recent, 0, 2)));
        }

        $chat->forceFill([
            'active_topic'             => $topic,
            'active_topic_updated_at'  => now(),
            'recent_topics'            => $recent !== [] ? $recent : null,
        ])->save();

        Log::debug('[active-topic] updated', [
            'chat_id'       => $chat->id,
            'topic'         => $topic,
            'recent_topics' => $recent,
        ]);
    }

    /**
     * Clear the active topic (e.g. on chat clear / client reset).
     */
    public function clear(Chat $chat): void
    {
        $chat->forceFill([
            'active_topic' => null,
            'active_topic_updated_at' => null,
        ])->save();
    }

    /**
     * Returns true when the message is a short vague follow-up that adds
     * no new topical information (should not replace the persisted topic).
     */
    public function isVagueFollowUp(string $body): bool
    {
        $trimmed = trim($body);

        if (mb_strlen($trimmed) > 60) {
            return false;
        }

        foreach (self::VAGUE_PATTERNS as $pattern) {
            if (preg_match($pattern, $trimmed) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Derive a compact topic string from the message body.
     * Enriches with domain hint keywords when present.
     */
    private function extractTopic(string $body, Chat $chat): ?string
    {
        $lower = mb_strtolower($body);

        // Detect primary domain from keywords.
        $domainHint = null;
        foreach (self::DOMAIN_KEYWORDS as $domain => $keywords) {
            foreach ($keywords as $kw) {
                if (str_contains($lower, $kw)) {
                    $domainHint = $domain;
                    break 2;
                }
            }
        }

        // Build topic: trim the body to a sensible length.
        $topic = Str::limit(preg_replace('/\s+/', ' ', $body) ?? $body, self::MAX_TOPIC_CHARS, '…');

        if ($domainHint !== null) {
            // Prefix the domain hint so RetrievalQueryBuilder can use it for filtering.
            $topic = "[{$domainHint}] {$topic}";
            $topic = Str::limit($topic, self::MAX_TOPIC_CHARS + 15, '…');
        }

        return $topic !== '' ? $topic : null;
    }
}
