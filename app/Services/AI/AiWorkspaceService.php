<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\User;
use RuntimeException;

final class AiWorkspaceService
{
    public function __construct(
        private readonly OpenAiChatService $openAi,
        private readonly AiWorkspaceSearchService $search,
    ) {}

    /**
     * @param  list<array{role: string, content: string}>  $history
     * @return array{
     *     reply: string,
     *     intent: string,
     *     contacts: list<array<string, mixed>>,
     *     media: list<array<string, mixed>>,
     *     filters_applied: array<string, mixed>
     * }
     */
    public function handle(User $user, string $message, array $history = []): array
    {
        $message = trim($message);
        if ($message === '') {
            throw new RuntimeException('Введите запрос.');
        }

        $parsed = $this->parseQuery($message, $history);
        $intent = (string) ($parsed['intent'] ?? 'answer');
        $contactFilters = is_array($parsed['contact_filters'] ?? null) ? $parsed['contact_filters'] : [];
        $mediaFilters = is_array($parsed['media_filters'] ?? null) ? $parsed['media_filters'] : [];

        $runContacts = $this->shouldSearchContacts($intent, $contactFilters);
        $runMedia = $this->shouldSearchMedia($intent, $mediaFilters);

        $contacts = $runContacts ? $this->search->searchContacts($user, $contactFilters) : [];
        $media = $runMedia ? $this->search->searchMedia($user, $mediaFilters) : [];

        $reply = trim((string) ($parsed['reply'] ?? ''));
        if ($reply === '' || ($runContacts || $runMedia)) {
            $reply = $this->buildResultReply($reply, $intent, $contacts, $media, $runContacts, $runMedia);
        }

        return [
            'reply' => $reply,
            'intent' => $intent,
            'contacts' => $contacts,
            'media' => $media,
            'filters_applied' => [
                'contacts' => $runContacts ? $contactFilters : null,
                'media' => $runMedia ? $mediaFilters : null,
            ],
        ];
    }

    /**
     * @param  list<array{role: string, content: string}>  $history
     * @return array<string, mixed>
     */
    private function parseQuery(string $message, array $history): array
    {
        $historyMessages = [];
        foreach (array_slice($history, -12) as $turn) {
            if (! is_array($turn)) {
                continue;
            }
            $role = $turn['role'] ?? null;
            $content = $turn['content'] ?? null;
            if (! in_array($role, ['user', 'assistant'], true) || ! is_string($content) || trim($content) === '') {
                continue;
            }
            $historyMessages[] = [
                'role' => $role,
                'content' => mb_substr(trim($content), 0, 4000),
            ];
        }

        $messages = [
            [
                'role' => 'system',
                'content' => <<<'PROMPT'
Ты — ассистент CRM ChatSwitch. Пользователь формулирует запрос на русском или казахском.
Верни JSON со схемой:
{
  "intent": "search_contacts" | "search_media" | "search_both" | "answer",
  "reply": "краткий ответ пользователю (1-3 предложения), можно пустую строку если всё в результатах",
  "contact_filters": {
    "text": "поиск по имени/телефону/компании или null",
    "company_name": "название компании или null",
    "phone_contains": "фрагмент номера или null",
    "has_unread_chat": true/false,
    "limit": 25
  },
  "media_filters": {
    "filename_contains": "часть имени файла или null",
    "text_query": "общий поиск по файлу/тексту сообщения или null",
    "mime_category": "image|video|document|audio|any",
    "contact_text": "имя или телефон контакта или null",
    "date_from": "YYYY-MM-DD или null",
    "date_to": "YYYY-MM-DD или null",
    "limit": 30
  }
}

Правила:
- Если просят найти клиентов, контакты, компании — intent search_contacts или search_both, заполни contact_filters.
- Если просят файлы, документы, фото, pdf, вложения — intent search_media или search_both, заполни media_filters.
- Для «непрочитанные» — has_unread_chat: true.
- Для типа файла: image, video, document, audio.
- Если вопрос общий без поиска — intent answer, пустые фильтры.
- Не выдумывай данные, только структура фильтров.
PROMPT,
            ],
            ...$historyMessages,
            ['role' => 'user', 'content' => $message],
        ];

        return $this->openAi->chatJson($messages, 0.2, 700);
    }

    /**
     * @param  array<string, mixed>  $contactFilters
     */
    private function shouldSearchContacts(string $intent, array $contactFilters): bool
    {
        if (in_array($intent, ['search_contacts', 'search_both'], true)) {
            return true;
        }

        foreach (['text', 'search', 'company_name', 'phone_contains'] as $key) {
            if (! empty($contactFilters[$key])) {
                return true;
            }
        }

        return filter_var($contactFilters['has_unread_chat'] ?? false, FILTER_VALIDATE_BOOL);
    }

    /**
     * @param  array<string, mixed>  $mediaFilters
     */
    private function shouldSearchMedia(string $intent, array $mediaFilters): bool
    {
        if (in_array($intent, ['search_media', 'search_both'], true)) {
            return true;
        }

        foreach (['filename_contains', 'filename', 'text_query', 'query', 'contact_text', 'date_from', 'date_to'] as $key) {
            if (! empty($mediaFilters[$key])) {
                return true;
            }
        }

        $category = $mediaFilters['mime_category'] ?? 'any';

        return is_string($category) && $category !== '' && $category !== 'any';
    }

    /**
     * @param  list<array<string, mixed>>  $contacts
     * @param  list<array<string, mixed>>  $media
     */
    private function buildResultReply(
        string $draftReply,
        string $intent,
        array $contacts,
        array $media,
        bool $ranContacts,
        bool $ranMedia,
    ): string {
        $parts = [];

        if ($draftReply !== '') {
            $parts[] = $draftReply;
        }

        if ($ranContacts) {
            $n = count($contacts);
            $parts[] = $n > 0
                ? "Найдено контактов: {$n}. Список справа — можно открыть диалог."
                : 'Контакты по заданным фильтрам не найдены. Уточните имя, телефон или компанию.';
        }

        if ($ranMedia) {
            $n = count($media);
            $parts[] = $n > 0
                ? "Найдено файлов: {$n}. Результаты в панели справа."
                : 'Файлы по запросу не найдены. Попробуйте другое имя файла или тип (pdf, фото).';
        }

        if ($parts === [] && $intent === 'answer') {
            return 'Задайте вопрос: например «найди клиентов с непрочитанными» или «покажи pdf за май».';
        }

        return implode("\n\n", $parts);
    }
}
