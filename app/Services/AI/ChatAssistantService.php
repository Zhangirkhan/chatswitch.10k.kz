<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\Chat;
use App\Models\Message;
use App\Models\User;
use App\Services\AI\Locale\LocalePromptAugmenter;
use App\Services\Knowledge\ProductMessageAttachmentService;
use App\Support\MessageInboundText;
use App\Support\OperatorSignature;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Ассистент оператора. Берёт переписку клиент↔операторы, формирует системный промпт и
 * передаёт диалог оператора с AI в OpenAI. Цель — подсказать ответ клиенту в стиле
 * существующих сотрудников (тон, лексика, структура).
 *
 * Контекст переписки кладём строго в system, чтобы пользователь/AI не могли «перетереть» его
 * собственными сообщениями. История диалога с AI приходит как user/assistant turns.
 */
final class ChatAssistantService
{
    private const HISTORY_LIMIT = 80;

    private const HISTORY_BODY_TRUNCATE = 600;

    private const ASSISTANT_HISTORY_LIMIT = 20;

    public function __construct(
        private readonly OpenAiChatService $openAi,
        private readonly OperatorCalendarContextBuilder $operatorCalendarContext,
        private readonly PromptBuilder $promptBuilder,
        private readonly ProductMessageAttachmentService $productAttachments,
        private readonly LocalePromptAugmenter $localeAugmenter,
    ) {}

    /**
     * @param  array<int, array{role: 'user'|'assistant', content: string}>  $assistantHistory
     *                                                                                          История переписки оператора с AI в этой панели (без system-сообщений).
     * @param  string  $userPrompt  Свежее сообщение оператора к AI.
     */
    /**
     * @return array{reply: string, product: array<string, mixed>|null}
     */
    public function reply(Chat $chat, User $operator, array $assistantHistory, string $userPrompt): array
    {
        $clientText = $this->latestClientMessageText($chat);
        $localeContextText = $clientText !== '' ? $clientText : $userPrompt;
        $localeAugment = $this->localeAugmenter->augment(
            $localeContextText,
            $chat,
            $chat->company_id ?? $operator->company_id,
        );
        $localeLabel = $localeAugment['profile']->dominantLabel();

        $knowledgePrompt = $this->promptBuilder->build(
            $chat,
            $operator,
            $userPrompt !== '' ? $userPrompt : 'Подготовь черновик ответа клиенту.',
        );
        $messages = [
            ['role' => 'system', 'content' => $this->buildSystemPrompt($chat, $operator, $localeLabel)],
            ...$knowledgePrompt['messages'],
            ['role' => 'system', 'content' => $this->buildConversationContext($chat)],
            ['role' => 'system', 'content' => $this->operatorCalendarContext->buildContextBlock($operator)],
            ...array_map(
                static fn (string $block): array => ['role' => 'system', 'content' => $block],
                $localeAugment['blocks'],
            ),
            ...$this->normalizeAssistantHistory($assistantHistory),
            ['role' => 'user', 'content' => $this->normalizeUserPrompt($userPrompt)],
        ];

        $reply = trim($this->openAi->chat(
            $messages,
            0.45,
            900,
            new AiUsageOptions('operator_assistant', $chat->company_id ?? $operator->company_id),
        ));
        $parsed = $this->productAttachments->stripAttachMarker($reply);
        $product = null;
        if ($parsed['product_id'] !== null) {
            $model = $this->productAttachments->findForChat($chat, $parsed['product_id']);
            if ($model !== null) {
                $product = $this->productAttachments->snapshot($model);
            }
        }

        return [
            'reply' => $parsed['reply'],
            'product' => $product,
        ];
    }

    private function buildSystemPrompt(Chat $chat, User $operator, string $clientLanguageLabel = 'не определён'): string
    {
        $contactName = $this->contactDisplay($chat);
        $operatorName = trim($operator->name) !== '' ? $operator->name : 'оператор';

        return <<<PROMPT
Ты — AI-ассистент оператора WhatsApp в системе Accel.
Твоя задача:
1. Анализировать переписку оператор↔клиент в текущем чате.
2. Подсказывать оператору, как ответить клиенту: формулировки, аргументы, тон.
3. Подражать стилю операторов из этой переписки — лексика, длина фраз, обращение,
   использование смайлов/эмодзи, формальность/неформальность.
4. Не выдумывать факты, которых нет в истории; если данных не хватает — честно
   попроси у оператора уточнить.
5. Отвечать оператору по-русски, кратко и по делу. Если оператор просит
   "сформулируй ответ клиенту" — давай готовый текст ответа в кавычках или
   отдельным блоком, а ниже короткое пояснение, почему такая формулировка.
   Черновик для клиента пиши на языке клиента (сейчас: {$clientLanguageLabel}), не на русском,
   если клиент пишет на казахском или смешанно.
6. Помни: оператор сейчас — {$operatorName}. Клиент — {$contactName}.
7. Не выдавай служебную «шапку» оператора (вида "*Имя (Роль)*\n...") — операторы
   не пишут её сами, её добавляет система автоматически.
{$this->calendarBehaviorInstructions()}

Ты не отправляешь ничего клиенту самостоятельно. Твои сообщения видит только оператор.
{$this->productAttachments->promptInstruction()}
PROMPT;
    }

    private function calendarBehaviorInstructions(): string
    {
        if (! $this->operatorCalendarContext->isModuleEnabled()) {
            return '';
        }

        return <<<'PROMPT'

8. Модуль «Календарь» в организации включён. Когда в переписке уместны звонок, встреча,
   дедлайн, «перезвонить в …», напоминание или фиксация договорённости — предложи оператору
   оформить запись в календаре Accel: рабочий заголовок, дата и время (ориентируйся на
   часовой пояс из блока «Календарь оператора» ниже), по желанию ответственного и краткое
   описание. Сверяйся с тем же блоком: там прошедшие, текущие и предстоящие записи этого
   оператора (с учётом прав видимости). Не предлагай слоты, которые пересекаются с уже
   существующими событиями; если время занято — предложи альтернативу или отметь конфликт.
   Если для календаря ничего не следует из диалога — не выдумывай события, можно не
   поднимать тему.
PROMPT;
    }

    private function buildConversationContext(Chat $chat): string
    {
        $messages = $this->loadRecentMessages($chat);

        if ($messages->isEmpty()) {
            return 'Контекст переписки: пусто (в этом чате ещё нет сообщений).';
        }

        $lines = ['История переписки в чате (от старого к новому, последние '.$messages->count().' сообщений):'];

        foreach ($messages as $message) {
            $lines[] = $this->formatMessageLine($message);
        }

        return implode("\n", $lines);
    }

    /**
     * @return Collection<int, Message>
     */
    private function loadRecentMessages(Chat $chat): Collection
    {
        return Message::query()
            ->where('chat_id', $chat->id)
            ->whereIn('direction', ['inbound', 'outbound'])
            ->whereNotIn('type', ['system'])
            ->with('sentByUser:id,name')
            ->orderByDesc('message_timestamp')
            ->orderByDesc('id')
            ->limit(self::HISTORY_LIMIT)
            ->get()
            ->reverse()
            ->values();
    }

    private function formatMessageLine(Message $message): string
    {
        $time = optional($message->message_timestamp)->format('Y-m-d H:i') ?? '';
        $body = $this->normalizeBody($message);
        $body = Str::limit($body, self::HISTORY_BODY_TRUNCATE, '…');

        if ($message->direction === 'outbound') {
            $author = $message->sentByUser?->name ?: ($message->sender_name ?: 'Оператор');

            return "[{$time}] Оператор {$author}: {$body}";
        }

        $author = $message->sender_name ?: 'Клиент';

        return "[{$time}] Клиент {$author}: {$body}";
    }

    private function normalizeBody(Message $message): string
    {
        if ($message->direction === 'inbound') {
            $body = trim(MessageInboundText::forMessage($message, voicePrefixWhenFromTranscript: true));
        } else {
            $body = OperatorSignature::strip(trim((string) ($message->body ?? '')));
        }

        if ($body === '') {
            $type = (string) ($message->type ?? 'chat');
            $body = '<сообщение типа "'.$type.'" без текста>';
        }

        return trim($body);
    }

    private function contactDisplay(Chat $chat): string
    {
        $chat->loadMissing('contact');
        $contact = $chat->contact;

        if ($contact === null) {
            return $chat->chat_name ?: 'клиент';
        }

        return $contact->name
            ?: $contact->push_name
            ?: $contact->phone_number
            ?: ($chat->chat_name ?: 'клиент');
    }

    /**
     * @param  array<int, array{role: string, content: string}>  $history
     * @return array<int, array{role: 'user'|'assistant', content: string}>
     */
    private function normalizeAssistantHistory(array $history): array
    {
        $result = [];

        foreach ($history as $entry) {
            $role = $entry['role'] ?? '';
            $content = trim((string) ($entry['content'] ?? ''));

            if ($content === '' || ! in_array($role, ['user', 'assistant'], true)) {
                continue;
            }

            $result[] = [
                'role' => $role,
                'content' => Str::limit($content, 4000, '…'),
            ];
        }

        if (count($result) > self::ASSISTANT_HISTORY_LIMIT) {
            $result = array_slice($result, -self::ASSISTANT_HISTORY_LIMIT);
        }

        return $result;
    }

    private function normalizeUserPrompt(string $prompt): string
    {
        $prompt = trim($prompt);

        if ($prompt === '') {
            return 'Проанализируй переписку и предложи лучший ответ клиенту прямо сейчас.';
        }

        return Str::limit($prompt, 4000, '…');
    }

    private function latestClientMessageText(Chat $chat): string
    {
        $body = Message::query()
            ->where('chat_id', $chat->id)
            ->where('direction', 'inbound')
            ->whereNotNull('body')
            ->orderByDesc('message_timestamp')
            ->orderByDesc('id')
            ->value('body');

        return trim((string) $body);
    }
}
