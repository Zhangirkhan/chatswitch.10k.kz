<?php

declare(strict_types=1);

namespace App\Services\AI\Retrieval;

use App\Models\Chat;
use App\Models\Message;
use App\Services\AI\ActiveTopicDetector;
use App\Support\MessageInboundText;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Builds an enriched RAG query string from the last client message + conversational context.
 *
 * Problem: when the client writes a short vague follow-up («Уточнили?», «Ну что?», «А цена?»)
 * the raw trigger text is useless as a vector query — the delivery/pricing topic is lost.
 *
 * Solution: for short or anaphoric messages, append the active topic from the chat
 * and a snippet of recent inbound context. This keeps retrieval semantically anchored
 * to the current thread without changing what the LLM sees in the user turn.
 *
 * Activated by the ai.retrieval_context_aware feature flag (default ON).
 */
final class RetrievalQueryBuilder
{
    /** Messages beyond this char length are considered substantive (no enrichment needed). */
    private const SUBSTANTIVE_MESSAGE_MIN_CHARS = 50;

    /** Number of recent inbound messages to pull context from. */
    private const RECENT_INBOUND_LIMIT = 3;

    /** Max length of the final enriched query string. */
    private const MAX_QUERY_CHARS = 400;

    public function __construct(
        private readonly ActiveTopicDetector $topicDetector,
    ) {}

    /**
     * Build the retrieval query for this trigger message and chat context.
     *
     * If the trigger is substantive (long, clearly states a topic), returns
     * the raw trigger body unchanged. For short/vague messages, appends
     * the active topic and/or recent inbound context.
     *
     * @param  string  $triggerBody  Raw text of the inbound trigger message.
     */
    public function build(string $triggerBody, Chat $chat): string
    {
        $trimmed = trim($triggerBody);

        // Substantive message — use as-is; no enrichment needed.
        if (mb_strlen($trimmed) >= self::SUBSTANTIVE_MESSAGE_MIN_CHARS
            && ! $this->topicDetector->isVagueFollowUp($trimmed)) {
            return $trimmed;
        }

        $parts = [];

        // Start with the trigger itself.
        if ($trimmed !== '') {
            $parts[] = $trimmed;
        }

        // Append active topic from the chat if different from trigger.
        $activeTopic = $chat->active_topic;
        if ($activeTopic !== null && $activeTopic !== '' && $activeTopic !== $trimmed) {
            // Strip leading domain hint prefix ("[delivery] …") for the query.
            $topicClean = (string) preg_replace('/^\[[a-z_]+\]\s*/u', '', $activeTopic);
            if ($topicClean !== '') {
                $parts[] = $topicClean;
            }
        }

        // Append a snippet of recent inbound messages if topic is still thin.
        if (count($parts) <= 1 || ($activeTopic === null)) {
            $recentContext = $this->recentInboundSnippet($chat);
            if ($recentContext !== '') {
                $parts[] = $recentContext;
            }
        }

        $query = implode('. ', array_filter(array_unique($parts)));
        $query = Str::limit($query, self::MAX_QUERY_CHARS, '');

        if ($query === '') {
            return $trimmed !== '' ? $trimmed : 'клиент';
        }

        Log::debug('[retrieval-query] enriched', [
            'chat_id' => $chat->id,
            'original' => $trimmed,
            'enriched' => $query,
        ]);

        return $query;
    }

    /**
     * Build a snippet from the last N distinct inbound messages (excluding the current trigger).
     */
    private function recentInboundSnippet(Chat $chat): string
    {
        $recent = Message::query()
            ->where('chat_id', $chat->id)
            ->where('direction', 'inbound')
            ->orderByDesc('message_timestamp')
            ->orderByDesc('id')
            ->limit(self::RECENT_INBOUND_LIMIT + 1) // +1 to skip the latest (that's the trigger)
            ->get(['id', 'body', 'metadata', 'message_timestamp']);

        // Drop the very latest (the trigger itself) and take the rest.
        $context = $recent->skip(1)->map(function (Message $m): string {
            $body = trim(MessageInboundText::forMessage($m));

            return $body;
        })->filter(fn (string $b): bool => $b !== '')->values();

        if ($context->isEmpty()) {
            return '';
        }

        // Join snippets oldest-first, capped at 150 chars total.
        return Str::limit(
            $context->reverse()->implode(' '),
            150,
            '',
        );
    }
}
