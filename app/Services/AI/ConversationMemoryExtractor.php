<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Enums\EntityMemorySubjectType;
use App\Models\Chat;
use App\Models\Message;
use App\Services\Memory\EntityMemoryService;
use App\Support\MessageInboundText;
use App\Support\OperatorSignature;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Extracts durable client facts from a recent conversation window and merges them
 * into the contact's EntityMemory via the managed "AI-факты (авто)" section.
 *
 * This is a system-level write; no User actor is required.
 * Activated only when the ai.memory_extraction feature flag is on.
 */
final class ConversationMemoryExtractor
{
    public function __construct(
        private readonly OpenAiChatService $openAi,
        private readonly EntityMemoryService $entityMemories,
    ) {}

    /**
     * Run extraction for the given chat and persist facts to the contact's memory.
     * Safe to call even when the chat has no contact (no-op).
     */
    public function extractAndPersist(Chat $chat): void
    {
        $facts = $this->extractFacts($chat);
        $this->persistFacts($chat, $facts);
    }

    /**
     * Extract facts from recent chat messages.
     * Returns an empty array when nothing meaningful was found or on error.
     *
     * @return array<string, mixed>
     */
    public function extractFacts(Chat $chat): array
    {
        if ($chat->contact_id === null) {
            return [];
        }

        $messages = $this->loadRecentMessages($chat);
        if ($messages->isEmpty()) {
            return [];
        }

        return $this->callLlm($chat, $messages) ?? [];
    }

    /**
     * Persist extracted facts to the contact's EntityMemory.
     *
     * @param  array<string, mixed>  $facts
     */
    public function persistFacts(Chat $chat, array $facts): void
    {
        if ($facts === [] || $chat->contact_id === null) {
            return;
        }

        try {
            $this->entityMemories->mergeAiFacts(
                EntityMemorySubjectType::Contact,
                (int) $chat->contact_id,
                $facts,
            );
        } catch (Throwable $e) {
            Log::warning('[memory-extractor] failed to persist facts', [
                'chat_id' => $chat->id,
                'contact_id' => $chat->contact_id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return \Illuminate\Support\Collection<int, Message>
     */
    private function loadRecentMessages(Chat $chat): \Illuminate\Support\Collection
    {
        $limit = max(5, (int) config('ai.memory_extraction_history_messages', 20));

        // Contact-scoped history: include messages from all of the contact's chats
        // so extraction captures context from multiple conversations (e.g. the client
        // stated their budget in chat A and their requirements in chat B).
        $useContactScope = $chat->contact_id !== null
            && (bool) config('ai.memory_extraction_contact_scope', true);

        $query = Message::query()
            ->whereIn('direction', ['inbound', 'outbound'])
            ->whereNotNull('body');

        if ($useContactScope) {
            // Fetch from all chats belonging to the same contact (same company).
            $query->whereHas('chat', fn ($q) => $q
                ->where('contact_id', $chat->contact_id)
                ->where('company_id', $chat->company_id));
        } else {
            $query->where('chat_id', $chat->id);
        }

        return $query
            ->orderByDesc('message_timestamp')
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['id', 'direction', 'body', 'metadata', 'message_timestamp', 'sender_name'])
            ->reverse()
            ->values();
    }

    /**
     * Build a history snippet and call the LLM to extract structured facts.
     *
     * @param  \Illuminate\Support\Collection<int, Message>  $messages
     * @return array<string, mixed>|null
     */
    private function callLlm(Chat $chat, \Illuminate\Support\Collection $messages): ?array
    {
        $historyLines = $messages->map(function (Message $msg): string {
            if ($msg->direction === 'inbound') {
                $body = trim(MessageInboundText::forMessage($msg));
                $name = $msg->sender_name ?: 'Клиент';

                return "[{$msg->message_timestamp?->format('Y-m-d H:i')}] {$name}: {$body}";
            }

            $body = OperatorSignature::strip(trim((string) $msg->body));
            $isAi = data_get($msg->metadata, 'ai.generated') === true;
            $author = $isAi ? 'AI-ассистент' : 'Сотрудник';

            return "[{$msg->message_timestamp?->format('Y-m-d H:i')}] {$author}: {$body}";
        })->implode("\n");

        // Load existing AI facts so the LLM can reconcile (update / keep / supersede).
        $existingFactsBlock = $this->buildExistingFactsBlock($chat);

        $systemPrompt = <<<'PROMPT'
Ты — аналитик CRM. Прочти фрагмент переписки с клиентом и обнови CRM-запись клиента.

Верни строго JSON-объект со следующими полями:
{
  "budget":       "бюджет или ценовой диапазон (актуальный)",
  "requirements": "продукты, услуги или требования клиента",
  "objections":   "возражения, сомнения или причины отказа",
  "agreements":   "договорённости, обещания, подтверждённые шаги",
  "preferences":  "предпочтения по каналу связи, времени, формату",
  "source":       "источник: откуда клиент узнал",
  "contact_info": "дополнительные контактные данные",
  "other":        "любая другая важная для продажи информация"
}

Правила:
- Пиши только факты из переписки или из существующей CRM-записи.
- Если в переписке клиент явно назвал новое значение — используй его (оно актуальнее).
- Если поле есть в существующей CRM-записи, но в переписке не упоминалось — сохрани старое значение.
- Если поле нечем заполнить ни из переписки, ни из CRM — НЕ включай его в ответ.
- Будь кратким: не более 2–3 предложений на поле.
PROMPT;

        $userContent = $existingFactsBlock !== ''
            ? "Существующая CRM-запись клиента:\n{$existingFactsBlock}\n\nПереписка (фрагмент):\n{$historyLines}"
            : "Переписка:\n{$historyLines}";

        try {
            $raw = $this->openAi->chatJson(
                [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $userContent],
                ],
                0.1,
                (int) config('ai.memory_extraction_max_tokens', 800),
                new AiUsageOptions('memory_extraction', $chat->company_id),
            );
        } catch (Throwable $e) {
            Log::warning('[memory-extractor] LLM call failed', [
                'chat_id' => $chat->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        return $this->sanitizeFacts($raw);
    }

    /**
     * Build a short human-readable block of existing AI facts for the LLM context.
     * Returns empty string when no prior facts exist.
     */
    private function buildExistingFactsBlock(Chat $chat): string
    {
        if ($chat->contact_id === null) {
            return '';
        }

        try {
            $existing = $this->entityMemories->readAiFacts(
                \App\Enums\EntityMemorySubjectType::Contact,
                (int) $chat->contact_id,
            );
        } catch (\Throwable) {
            return '';
        }

        if ($existing === []) {
            return '';
        }

        $labels = [
            'budget'       => 'Бюджет',
            'requirements' => 'Требования',
            'objections'   => 'Возражения',
            'agreements'   => 'Договорённости',
            'preferences'  => 'Предпочтения',
            'source'       => 'Источник лида',
            'contact_info' => 'Контактные данные',
            'other'        => 'Прочее',
        ];

        $lines = [];
        foreach ($labels as $key => $label) {
            $val = $existing[$key] ?? null;
            if ($val !== null && $val !== '') {
                $lines[] = "{$label}: {$val}";
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Keep only string/array scalar values; drop nulls and empty strings.
     *
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private function sanitizeFacts(array $raw): array
    {
        $allowed = ['budget', 'requirements', 'objections', 'agreements', 'preferences', 'source', 'contact_info', 'other'];
        $maxChars = (int) config('ai.memory_extraction_max_chars', 8000);
        $result = [];
        $total = 0;

        foreach ($allowed as $key) {
            $value = $raw[$key] ?? null;
            if ($value === null || $value === '' || $value === []) {
                continue;
            }

            if (is_array($value)) {
                $value = implode(', ', array_filter(array_map('strval', $value)));
            }

            $value = mb_substr(trim((string) $value), 0, 500);
            if ($value === '') {
                continue;
            }

            $total += mb_strlen($value);
            if ($total > $maxChars) {
                break;
            }

            $result[$key] = $value;
        }

        return $result;
    }
}
