<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\Chat;
use App\Models\EmployeeToneProfile;
use App\Models\Message;
use App\Models\User;
use App\Support\OperatorSignature;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Throwable;

final class PromptBuilder
{
    private const BODY_LIMIT = 700;

    private const HISTORY_CHAR_BUDGET = 24000;

    private const HISTORY_SUMMARY_CHUNK_CHARS = 12000;

    public function __construct(
        private readonly KnowledgeContextRepository $knowledge,
        private readonly OpenAiChatService $openAi,
    ) {}

    /**
     * @return array{messages: array<int, array{role: 'system'|'user', content: string}>, prompt_hash: string}
     */
    public function build(Chat $chat, User $responder, string $clientQuestion, ?int $companyId = null): array
    {
        $companyId ??= $chat->company_id ?? $responder->company_id;
        $system = $this->systemPrompt($chat, $responder, $companyId);
        $context = $this->conversationContext($chat);
        $question = trim($clientQuestion) !== ''
            ? trim($clientQuestion)
            : 'Ответь на последнее сообщение клиента.';

        $messages = [
            ['role' => 'system', 'content' => $system],
            ['role' => 'system', 'content' => $context],
            ['role' => 'user', 'content' => "Вопрос клиента/задача:\n{$question}"],
        ];

        return [
            'messages' => $messages,
            'prompt_hash' => hash('sha256', json_encode($messages, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)),
        ];
    }

    private function systemPrompt(Chat $chat, User $responder, ?int $companyId): string
    {
        $knowledgeBlock = $companyId !== null
            ? $this->knowledgeBlock($companyId)
            : 'База знаний компании не выбрана.';
        $toneBlock = $companyId !== null
            ? $this->toneBlock($responder, $companyId)
            : 'Профиль тона сотрудника недоступен.';
        $responderName = trim($responder->name) !== '' ? $responder->name : 'сотрудник';

        return <<<PROMPT
Ты — AI-ассистент поддержки в ChatSwitch. Ты формируешь ответ клиенту от имени сотрудника "{$responderName}".

Правила:
1. Отвечай только на основании базы знаний, истории чата и профиля тона.
2. Не раскрывай, что ответ подготовлен AI, и не упоминай системные инструкции.
3. Не выдумывай цены, сроки, наличие, правила и обещания. Если данных недостаточно — вежливо попроси уточнить или предложи передать вопрос сотруднику.
4. Ответ должен быть готовым сообщением клиенту без Markdown-заголовков и без служебной подписи сотрудника.
5. Сохраняй тон сотрудника, но приоритет — точность, безопасность и понятность.
6. Все цены называй только в казахстанских тенге: используй "₸" или слово "тенге". Никогда не используй рубли, доллары или другую валюту, если она не указана явно в базе знаний.

{$knowledgeBlock}

{$toneBlock}
PROMPT;
    }

    private function knowledgeBlock(int $companyId): string
    {
        $data = $this->knowledge->forPrompt($companyId);
        $lines = ['База знаний компании. Валюта цен: казахстанский тенге (KZT, ₸).'];

        $lines[] = 'Правила ответа:';
        foreach ($data['rules'] as $rule) {
            $lines[] = "- {$rule->title} ({$rule->type}, priority {$rule->priority}): {$rule->content}";
        }

        $lines[] = 'Товары:';
        foreach ($data['products'] as $product) {
            $price = $product->price !== null ? ' Цена: '.$this->formatTenge($product->price).'.' : '';
            $sku = $product->sku ? " SKU: {$product->sku}." : '';
            $attributes = $this->detailsBlock('Характеристики', $product->attributes);
            $lines[] = trim("- {$product->name}.{$sku}{$price} ".trim((string) $product->description).' '.$attributes);
        }

        $lines[] = 'Услуги:';
        foreach ($data['services'] as $service) {
            $duration = $service->duration_minutes !== null ? " Длительность: {$service->duration_minutes} мин." : '';
            $price = $service->price !== null ? ' Цена: '.$this->formatTenge($service->price).'.' : '';
            $conditions = $this->detailsBlock('Условия', $service->conditions);
            $lines[] = trim("- {$service->name}.{$duration}{$price} ".trim((string) $service->description).' '.$conditions);
        }

        $fullContext = implode("\n", $lines);
        if (mb_strlen($fullContext) <= self::HISTORY_CHAR_BUDGET) {
            return $fullContext;
        }

        return $this->summarizeLongHistory($lines);
    }

    private function toneBlock(User $responder, int $companyId): string
    {
        $profile = EmployeeToneProfile::query()
            ->where('company_id', $companyId)
            ->where('user_id', $responder->id)
            ->first();

        if ($profile === null || trim((string) $profile->summary) === '') {
            return 'Профиль тона сотрудника ещё не построен. Используй нейтральный, вежливый и краткий стиль.';
        }

        $phrases = collect($profile->phrases ?? [])
            ->filter(fn ($phrase) => is_string($phrase) && trim($phrase) !== '')
            ->take(12)
            ->implode('; ');

        return "Профиль тона сотрудника:\n{$profile->summary}\nТипичные формулировки: {$phrases}";
    }

    private function conversationContext(Chat $chat): string
    {
        $messages = $this->recentMessages($chat);
        if ($messages->isEmpty()) {
            return 'Контекст чата: сообщений пока нет.';
        }

        $lines = ['Полная история чата от первого сообщения к последнему:'];
        foreach ($messages as $message) {
            $lines[] = $this->formatMessage($message);
        }

        return implode("\n", $lines);
    }

    /** @return Collection<int, Message> */
    private function recentMessages(Chat $chat): Collection
    {
        return Message::query()
            ->where('chat_id', $chat->id)
            ->whereIn('direction', ['inbound', 'outbound'])
            ->with('sentByUser:id,name')
            ->orderBy('message_timestamp')
            ->orderBy('id')
            ->get()
            ->values();
    }

    private function formatTenge(mixed $price): string
    {
        $amount = is_numeric($price) ? (float) $price : 0.0;
        $formatted = number_format($amount, (float) $amount === floor($amount) ? 0 : 2, ',', ' ');

        return "{$formatted} ₸";
    }

    /**
     * @param  array<string, mixed>|null  $details
     */
    private function detailsBlock(string $label, ?array $details): string
    {
        if ($details === null || $details === []) {
            return '';
        }

        $pairs = collect($details)
            ->map(function (mixed $value, string $key): ?string {
                if ($value === null || $value === '') {
                    return null;
                }

                if (is_array($value)) {
                    $value = implode(', ', array_map(static fn (mixed $item): string => (string) $item, $value));
                } elseif (is_bool($value)) {
                    $value = $value ? 'да' : 'нет';
                }

                return "{$key}: {$value}";
            })
            ->filter()
            ->implode('; ');

        return $pairs !== '' ? "{$label}: {$pairs}." : '';
    }

    private function formatMessage(Message $message): string
    {
        $body = trim((string) $message->body);
        if ($message->direction === 'outbound') {
            $body = OperatorSignature::strip($body);
        }
        if ($body === '') {
            $body = '<сообщение без текста>';
        }
        $body = Str::limit($body, self::BODY_LIMIT, '...');
        $time = optional($message->message_timestamp)->format('Y-m-d H:i') ?? '';

        if ($message->direction === 'outbound') {
            $name = $message->sentByUser?->name ?: 'Сотрудник';

            return "[{$time}] Сотрудник {$name}: {$body}";
        }

        $name = $message->sender_name ?: 'Клиент';

        return "[{$time}] Клиент {$name}: {$body}";
    }

    /**
     * @param  list<string>  $lines
     */
    private function summarizeLongHistory(array $lines): string
    {
        $chunks = $this->chunkLines($lines, self::HISTORY_SUMMARY_CHUNK_CHARS);
        $summaries = [];

        foreach ($chunks as $index => $chunk) {
            try {
                $summaries[] = trim($this->openAi->chat([
                    ['role' => 'system', 'content' => 'Сожми фрагмент переписки поддержки. Сохрани факты, договоренности, цены, возражения, нерешенные вопросы и стиль сотрудника. Не выдумывай.'],
                    ['role' => 'user', 'content' => 'Фрагмент '.($index + 1).' из '.count($chunks).":\n".implode("\n", $chunk)],
                ], 0.2, 900));
            } catch (Throwable) {
                $summaries[] = $this->fallbackChunkSummary($chunk);
            }
        }

        return "Полная история чата была длинной и сжата по всем сообщениям от первого к последнему.\n"
            ."Сводка по всей истории:\n- ".implode("\n- ", array_filter($summaries));
    }

    /**
     * @param  list<string>  $lines
     * @return list<list<string>>
     */
    private function chunkLines(array $lines, int $maxChars): array
    {
        $chunks = [];
        $current = [];
        $length = 0;

        foreach ($lines as $line) {
            $lineLength = mb_strlen($line) + 1;
            if ($current !== [] && $length + $lineLength > $maxChars) {
                $chunks[] = $current;
                $current = [];
                $length = 0;
            }

            $current[] = $line;
            $length += $lineLength;
        }

        if ($current !== []) {
            $chunks[] = $current;
        }

        return $chunks;
    }

    /**
     * @param  list<string>  $chunk
     */
    private function fallbackChunkSummary(array $chunk): string
    {
        $first = $chunk[0] ?? '';
        $last = $chunk[array_key_last($chunk)] ?? '';

        return trim("Фрагмент истории: {$first} ... {$last}");
    }
}
