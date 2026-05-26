<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\User;
use App\Services\AI\Locale\KazakhstanLocaleProfile;
use App\Services\AI\Locale\LocalePromptAugmenter;
use RuntimeException;

final class AiWorkspaceService
{
    public function __construct(
        private readonly OpenAiChatService $openAi,
        private readonly AiWorkspaceSearchService $search,
        private readonly AiWorkspaceVisualizationService $visualizations,
        private readonly AiWorkspaceContextBuilder $contextBuilder,
        private readonly LocalePromptAugmenter $localeAugmenter,
    ) {}

    /**
     * @param  list<array{role: string, content: string}>  $history
     * @return array{
     *     reply: string,
     *     intent: string,
     *     contacts: list<array<string, mixed>>,
     *     media: list<array<string, mixed>>,
     *     messages: list<array<string, mixed>>,
     *     funnel_deals: list<array<string, mixed>>,
     *     calendar_events: list<array<string, mixed>>,
     *     calendar_meta: array<string, mixed>,
     *     department_posts: list<array<string, mixed>>,
     *     employees: list<array<string, mixed>>,
     *     visualizations: list<array<string, mixed>>,
     *     filters_applied: array<string, mixed>
     * }
     */
    public function handle(User $user, string $message, array $history = []): array
    {
        $message = trim($message);
        if ($message === '') {
            throw new RuntimeException('Введите запрос.');
        }

        $localeAugment = $this->localeAugmenter->augment($message);
        $localeProfile = $localeAugment['profile'];

        $parsed = $this->parseQuery($message, $history, $localeProfile);
        $intent = (string) ($parsed['intent'] ?? 'answer');

        $contactFilters = is_array($parsed['contact_filters'] ?? null) ? $parsed['contact_filters'] : [];
        $mediaFilters = is_array($parsed['media_filters'] ?? null) ? $parsed['media_filters'] : [];
        $messageFilters = is_array($parsed['message_filters'] ?? null) ? $parsed['message_filters'] : [];
        $calendarFilters = is_array($parsed['calendar_filters'] ?? null) ? $parsed['calendar_filters'] : [];
        $funnelFilters = is_array($parsed['funnel_filters'] ?? null) ? $parsed['funnel_filters'] : [];
        $taskFilters = is_array($parsed['task_filters'] ?? null) ? $parsed['task_filters'] : [];
        $employeeFilters = is_array($parsed['employee_filters'] ?? null) ? $parsed['employee_filters'] : [];

        $runContacts = $this->shouldSearchContacts($intent, $contactFilters);
        $runMedia = $this->shouldSearchMedia($intent, $mediaFilters);
        $runMessages = $this->shouldSearchMessages($intent, $messageFilters);
        $runCalendar = $this->shouldSearchCalendar($intent, $calendarFilters);
        $runFunnel = $this->shouldSearchFunnel($intent, $funnelFilters);
        $runTasks = $this->shouldSearchTasks($intent, $taskFilters);
        $runEmployees = $this->shouldSearchEmployees($intent, $employeeFilters);

        $contacts = $runContacts ? $this->search->searchContacts($user, $contactFilters) : [];
        $media = $runMedia ? $this->search->searchMedia($user, $mediaFilters) : [];
        $messages = $runMessages ? $this->search->searchMessages($user, $messageFilters) : [];
        $funnelDeals = $runFunnel ? $this->search->searchFunnelDeals($user, $funnelFilters) : [];
        $calendarBundle = $runCalendar ? $this->search->searchCalendarEvents($user, $calendarFilters) : ['meta' => [], 'events' => []];
        $calendarEvents = $calendarBundle['events'];
        $calendarMeta = $calendarBundle['meta'];
        $departmentPosts = $runTasks ? $this->search->searchDepartmentPosts($user, $taskFilters) : [];
        $employees = $runEmployees ? $this->search->searchEmployees($user, $employeeFilters) : [];

        $draftReply = trim((string) ($parsed['reply'] ?? ''));

        $contextPayload = [
            'contacts' => $contacts,
            'media' => $media,
            'messages' => $messages,
            'funnel_deals' => $funnelDeals,
            'calendar_events' => $calendarEvents,
            'calendar_meta' => $calendarMeta,
            'department_posts' => $departmentPosts,
            'employees' => $employees,
        ];

        $dataContext = $this->contextBuilder->build($contextPayload);
        $ranAnySearch = $runContacts || $runMedia || $runMessages || $runCalendar || $runFunnel || $runTasks || $runEmployees;

        if ($dataContext !== '') {
            $reply = $this->synthesizeReply($message, $history, $dataContext, $draftReply, $localeProfile);
        } elseif ($ranAnySearch) {
            $reply = $this->buildResultReply($draftReply, $intent, $contacts, $media, $runContacts, $runMedia);
        } else {
            $reply = $draftReply !== ''
                ? $draftReply
                : 'Задайте вопрос про клиентов, чаты, воронки, календарь или задачи отдела.';
        }

        $aiVisualizations = is_array($parsed['visualizations'] ?? null) ? $parsed['visualizations'] : [];
        $visualizations = $this->visualizations->resolve(
            $aiVisualizations,
            $message,
            $contacts,
            $media,
            $runContacts,
            $runMedia,
        );

        return [
            'reply' => $reply,
            'intent' => $intent,
            'contacts' => $contacts,
            'media' => $media,
            'messages' => $messages,
            'funnel_deals' => $funnelDeals,
            'calendar_events' => $calendarEvents,
            'calendar_meta' => $calendarMeta,
            'department_posts' => $departmentPosts,
            'employees' => $employees,
            'visualizations' => $visualizations,
            'filters_applied' => [
                'contacts' => $runContacts ? $contactFilters : null,
                'media' => $runMedia ? $mediaFilters : null,
                'messages' => $runMessages ? $messageFilters : null,
                'calendar' => $runCalendar ? $calendarFilters : null,
                'funnel' => $runFunnel ? $funnelFilters : null,
                'tasks' => $runTasks ? $taskFilters : null,
                'employees' => $runEmployees ? $employeeFilters : null,
            ],
        ];
    }

    /**
     * @param  list<array{role: string, content: string}>  $history
     */
    private function synthesizeReply(
        string $message,
        array $history,
        string $dataContext,
        string $draftReply,
        KazakhstanLocaleProfile $localeProfile,
    ): string {
        $historyMessages = [];
        foreach (array_slice($history, -8) as $turn) {
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
                'content' => mb_substr(trim($content), 0, 2000),
            ];
        }

        $languageRule = $this->localeAugmenter->workspaceLanguageInstruction($localeProfile);

        $messages = [
            [
                'role' => 'system',
                'content' => <<<PROMPT
Ты — ассистент Accel. Отвечай кратко и по делу (2–6 предложений или список).
{$languageRule}
Используй ТОЛЬКО данные из блока «Данные из системы». Не выдумывай записи, сделки и сообщения.
Если доступ запрещён — объясни это вежливо.
Если данных нет — скажи прямо и предложи уточнить запрос.
Для календаря явно укажи занятые слоты; свободное время — промежутки без записей в указанном периоде.
PROMPT,
            ],
            ...$historyMessages,
            [
                'role' => 'user',
                'content' => trim(
                    ($draftReply !== '' ? "Черновик ответа: {$draftReply}\n\n" : '')
                    ."Вопрос пользователя: {$message}\n\nДанные из системы:\n{$dataContext}",
                ),
            ],
        ];

        return trim($this->openAi->chat($messages, 0.25, 900));
    }

    /**
     * @param  list<array{role: string, content: string}>  $history
     * @return array<string, mixed>
     */
    private function parseQuery(string $message, array $history, KazakhstanLocaleProfile $localeProfile): array
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

        $languageRule = $this->localeAugmenter->workspaceLanguageInstruction($localeProfile);

        $messages = [
            [
                'role' => 'system',
                'content' => <<<PROMPT
Ты — ассистент CRM Accel. Пользователь формулирует запрос на русском или казахском.
Черновик reply в JSON пиши на том же языке, что запрос: {$languageRule}
Верни JSON:
{
  "intent": "search_contacts|search_media|search_messages|search_funnel|search_calendar|search_tasks|search_employees|search_both|answer",
  "reply": "краткий черновик ответа или пустая строка",
  "contact_filters": { "text", "company_name", "phone_contains", "has_unread_chat", "limit" },
  "media_filters": { "filename_contains", "text_query", "mime_category", "contact_text", "date_from", "date_to", "limit" },
  "message_filters": { "text_query", "contact_text", "date_from", "date_to", "limit" },
  "calendar_filters": { "employee_name", "employee_id", "date_from", "date_to", "days_ahead", "limit" },
  "funnel_filters": { "funnel_name", "stage_name", "assignee_name", "search", "scope": "all|mine|department", "limit" },
  "task_filters": { "assignee_name", "department_name", "status": "open|in_progress|done", "text", "limit" },
  "employee_filters": { "name", "list_department": true/false, "department_name" },
  "visualizations": [ chart | mermaid ]
}

Правила маршрутизации:
- Клиенты, контакты, компании, непрочитанные → contact_filters + intent search_contacts/search_both.
- Файлы, вложения, pdf, фото → media_filters.
- Поиск по тексту переписки, «что писали», «найди сообщение» → message_filters.
- Воронка, сделки, этапы, лиды → funnel_filters.
- Календарь, записи, занят/свободен, расписание сотрудника → calendar_filters (employee_name).
- Задачи отдела, организация, дедлайны → task_filters.
- Кто в отделе, список сотрудников → employee_filters list_department=true.
- График/диаграмма → visualizations (chart с реальными числами или пусто).
- Mermaid только для схем процессов; id латиницей, подписи в ["кавычках"].

Не выдумывай данные — только фильтры.
PROMPT,
            ],
            ...$historyMessages,
            ['role' => 'user', 'content' => $message],
        ];

        return $this->openAi->chatJson($messages, 0.2, 1600);
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
     * @param  array<string, mixed>  $filters
     */
    private function shouldSearchMessages(string $intent, array $filters): bool
    {
        if ($intent === 'search_messages') {
            return true;
        }

        return ! empty($filters['text_query']) || ! empty($filters['query']);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function shouldSearchCalendar(string $intent, array $filters): bool
    {
        if ($intent === 'search_calendar') {
            return true;
        }

        foreach (['employee_name', 'assignee_name', 'employee_id', 'date_from', 'date_to', 'days_ahead'] as $key) {
            if (! empty($filters[$key])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function shouldSearchFunnel(string $intent, array $filters): bool
    {
        if ($intent === 'search_funnel') {
            return true;
        }

        foreach (['funnel_name', 'stage_name', 'assignee_name', 'search', 'text'] as $key) {
            if (! empty($filters[$key])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function shouldSearchTasks(string $intent, array $filters): bool
    {
        if ($intent === 'search_tasks') {
            return true;
        }

        foreach (['assignee_name', 'department_name', 'status', 'text', 'search'] as $key) {
            if (! empty($filters[$key])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function shouldSearchEmployees(string $intent, array $filters): bool
    {
        if ($intent === 'search_employees') {
            return true;
        }

        if (filter_var($filters['list_department'] ?? false, FILTER_VALIDATE_BOOL)) {
            return true;
        }

        return ! empty($filters['name']) || ! empty($filters['text']);
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
            return 'Задайте вопрос про клиентов, чаты, воронки, календарь или задачи отдела.';
        }

        return implode("\n\n", $parts);
    }
}
