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
        if ($chat->contact_id === null) {
            return;
        }

        $messages = $this->loadRecentMessages($chat);
        if ($messages->isEmpty()) {
            return;
        }

        $facts = $this->callLlm($chat, $messages);
        if ($facts === null || $facts === []) {
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

        return Message::query()
            ->where('chat_id', $chat->id)
            ->whereIn('direction', ['inbound', 'outbound'])
            ->whereNotNull('body')
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

        $systemPrompt = <<<'PROMPT'
Ты — аналитик CRM. Прочти фрагмент переписки с клиентом и извлеки только то, что клиент явно сказал или подтвердил.

Верни строго JSON-объект со следующими полями (пропусти поле, если клиент ничего об этом не говорил):
{
  "budget":       "бюджет или ценовой диапазон, который клиент назвал",
  "requirements": "продукты, услуги или требования, которые клиент упомянул",
  "objections":   "возражения, сомнения или причины отказа, которые клиент высказал",
  "agreements":   "договорённости, обещания, подтверждённые шаги (что именно и когда)",
  "preferences":  "предпочтения по каналу связи, времени, формату",
  "source":       "источник: откуда клиент узнал (реклама, рекомендация и т.д.)",
  "contact_info": "дополнительные контактные данные, которые клиент сообщил",
  "other":        "любая другая важная для продажи информация"
}

Правила:
- Пиши только факты из переписки. Не домысливай и не обобщай.
- Если поле нечем заполнить — НЕ включай его в ответ.
- Будь кратким: не более 2–3 предложений на поле.
PROMPT;

        try {
            $raw = $this->openAi->chatJson(
                [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => "Переписка:\n{$historyLines}"],
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
