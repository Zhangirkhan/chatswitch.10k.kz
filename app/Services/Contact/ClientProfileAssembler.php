<?php

declare(strict_types=1);

namespace App\Services\Contact;

use App\Enums\EntityMemorySubjectType;
use App\Models\Contact;
use App\Models\EntityMemory;
use App\Models\User;
use App\Services\Memory\EntityMemoryService;
use App\Support\ClientProfileFieldHelper;
use App\Support\ContactFieldCatalog;
use App\Support\TenantCompany;
use Throwable;

final class ClientProfileAssembler
{
    public function __construct(
        private readonly ContactCardAssembler $cardAssembler,
        private readonly ContactBucketResolver $buckets,
        private readonly EntityMemoryService $entityMemory,
        private readonly ContactProfileFieldFilter $fieldFilter,
    ) {}

    /**
     * @return array{
     *     contact_id: int,
     *     display_name: string,
     *     sections: list<array<string, mixed>>,
     *     memory: array{content: string, updated_at: string|null, memory_contact_id: int|null}
     * }
     */
    public function build(User $user, Contact $contact, ?int $preferredChatId = null): array
    {
        $card = $this->cardAssembler->build($user, $contact, $preferredChatId);
        $identity = is_array($card['identity'] ?? null) ? $card['identity'] : [];
        $activity = is_array($card['activity'] ?? null) ? $card['activity'] : [];
        $crm = is_array($card['crm'] ?? null) ? $card['crm'] : [];
        $channels = is_array($card['channels'] ?? null) ? $card['channels'] : [];

        $chatIds = collect($channels)
            ->pluck('chat_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->all();
        $snippets = $this->cardAssembler->recentMessageSnippets($chatIds, 10);

        $contactIds = $this->buckets->bucketIds($contact);
        $memory = $this->resolveMemory($contactIds, $contact->id);

        // Structured AI-facts for the contact panel (budget, requirements, etc.)
        // Exposed separately so the UI can render them as labelled fields rather
        // than a raw markdown blob.
        $aiFacts = $this->resolveAiFacts($contact->id);

        return $this->fieldFilter->apply($contact, [
            'contact_id' => $contact->id,
            'display_name' => (string) ($identity['display_name'] ?? 'Без имени'),
            'sections' => [
                $this->basicSection($contact, $identity, $crm),
                $this->contactsSection($identity, $crm, $channels, $memory['content'], $snippets),
                $this->financeSection(),
                $this->b2bSection($identity, $crm),
                $this->historySection($activity, $crm),
                $this->tasksNotesSection($crm, $memory['content']),
            ],
            'memory' => $memory,
            'ai_facts' => $aiFacts,
        ]);
    }

    /**
     * @param  array<string, mixed>  $identity
     * @param  array<string, mixed>  $crm
     * @return array<string, mixed>
     */
    private function basicSection(Contact $contact, array $identity, array $crm): array
    {
        $deal = is_array($crm['deal'] ?? null) ? $crm['deal'] : null;
        $stage = is_array($deal['stage'] ?? null) ? $deal['stage'] : null;
        $funnel = is_array($deal['funnel'] ?? null) ? $deal['funnel'] : null;
        $assignees = is_array($deal['assignees'] ?? null) ? $deal['assignees'] : [];
        $assigneeNames = collect($assignees)
            ->map(fn (array $row): string => trim((string) ($row['name'] ?? '')))
            ->filter()
            ->values()
            ->all();

        $companies = collect($crm['companies'] ?? [])
            ->map(fn (array $row): string => (string) ($row['name'] ?? ''))
            ->filter()
            ->values()
            ->all();

        $fields = [
            $this->field('Имя', (string) ($identity['display_name'] ?? '—'), 'crm'),
            $this->field('ID контакта', (string) $contact->id, 'crm'),
        ];

        if ($stage !== null) {
            $fields[] = $this->field('Этап воронки', (string) ($stage['name'] ?? '—'), 'crm');
        } elseif ($funnel !== null) {
            $fields[] = $this->field('Воронка', (string) ($funnel['name'] ?? '—'), 'crm');
        }

        if ($companies !== []) {
            $fields[] = $this->field('Компании / сегмент', implode(', ', $companies), 'crm');
        }

        if ($assigneeNames !== []) {
            $fields[] = $this->field('Ответственный', implode(', ', $assigneeNames), 'crm');
        }

        if ($deal !== null && isset($deal['progress_percent'])) {
            $fields[] = $this->field('Прогресс сделки', (string) $deal['progress_percent'].'%', 'crm');
        }

        return [
            'key' => 'basic',
            'title' => 'Основное',
            'semantic' => 'who',
            'fields' => $fields,
        ];
    }

    /**
     * @param  array<string, mixed>  $identity
     * @param  array<string, mixed>  $crm
     * @param  list<array<string, mixed>>  $channels
     * @param  list<array{direction: string, body: string|null, at: string|null}>  $snippets
     * @return array<string, mixed>
     */
    private function contactsSection(array $identity, array $crm, array $channels, string $memoryContent, array $snippets): array
    {
        $fields = [];

        $phoneDisplay = trim((string) ($identity['phone_display'] ?? ''));
        if ($phoneDisplay !== '') {
            $fields[] = $this->field('Телефон', $phoneDisplay, 'crm');
        }

        $leadId = trim((string) ($identity['lead_id'] ?? ''));
        if ($leadId !== '') {
            $fields[] = $this->field('ID лида WhatsApp', $leadId, 'crm');
        }

        $fields = ClientProfileFieldHelper::mergeUnique(
            $fields,
            $this->fieldsFromMemory($memoryContent, ['Адрес', 'Город', 'Район']),
        );
        $fields = ClientProfileFieldHelper::mergeUnique(
            $fields,
            $this->factsMatching($crm, '/адрес|address|локац|улиц|город|район/iu'),
        );
        $fields = ClientProfileFieldHelper::mergeUnique($fields, $this->fieldsFromSnippets($snippets));

        $channelLabels = collect($channels)
            ->map(function (array $row): string {
                $label = trim((string) ($row['session_label'] ?? ''));
                $sessionPhone = trim((string) ($row['session_phone_display'] ?? ''));
                if ($sessionPhone !== '') {
                    $label = $label !== '' ? "{$label} ({$sessionPhone})" : $sessionPhone;
                }
                $chatName = trim((string) ($row['chat_name'] ?? ''));

                return $chatName !== '' && $chatName !== $label
                    ? "{$label} ({$chatName})"
                    : $label;
            })
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($channelLabels !== []) {
            $fields[] = $this->field('Писал на WA-номера', implode('; ', $channelLabels), 'crm');
        }

        foreach ($crm['companies'] ?? [] as $company) {
            if (! is_array($company)) {
                continue;
            }
            $name = (string) ($company['name'] ?? '');
            foreach (['phone' => 'Телефон компании', 'email' => 'Email компании', 'website' => 'Сайт компании'] as $key => $label) {
                $value = trim((string) ($company[$key] ?? ''));
                if ($value !== '') {
                    $fields[] = $this->field($name !== '' ? "{$label} ({$name})" : $label, $value, 'crm');
                }
            }
        }

        if ($fields === []) {
            $fields[] = $this->field('Контакты', 'Нет данных', 'crm');
        }

        return [
            'key' => 'contacts',
            'title' => 'Контакты',
            'semantic' => 'context',
            'fields' => $fields,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function financeSection(): array
    {
        return [
            'key' => 'finance',
            'title' => 'Финансы',
            'semantic' => 'agreements',
            'status' => 'unavailable',
            'message' => 'Нет данных — подключим при интеграции заказов/оплат',
            'fields' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $identity
     * @param  array<string, mixed>  $crm
     * @return array<string, mixed>
     */
    private function b2bSection(array $identity, array $crm): array
    {
        $isBusiness = (bool) ($identity['is_business'] ?? false);
        $fields = [
            $this->field('Тип', $isBusiness ? 'B2B' : 'B2C', 'crm'),
        ];

        foreach ($crm['companies'] ?? [] as $company) {
            if (! is_array($company)) {
                continue;
            }
            $name = trim((string) ($company['name'] ?? ''));
            $position = trim((string) ($company['position'] ?? ''));
            if ($name !== '') {
                $value = $position !== '' ? "{$name} — {$position}" : $name;
                $fields[] = $this->field('Компания', $value, 'crm');
            }
            $description = trim((string) ($company['description'] ?? ''));
            if ($description !== '') {
                $fields[] = $this->field('О компании', mb_substr($description, 0, 300), 'crm');
            }
        }

        if (! $isBusiness) {
            $displayName = trim((string) ($identity['display_name'] ?? ''));
            $phoneDisplay = trim((string) ($identity['phone_display'] ?? ''));
            if ($displayName !== '') {
                $fields[] = $this->field('Клиент', $displayName, 'crm');
            }
            if ($phoneDisplay !== '') {
                $fields[] = $this->field('Телефон', $phoneDisplay, 'crm');
            }
        }

        return [
            'key' => 'b2b',
            'title' => 'B2B / B2C',
            'semantic' => 'who',
            'fields' => $fields,
        ];
    }

    /**
     * @param  array<string, mixed>  $activity
     * @param  array<string, mixed>  $crm
     * @return array<string, mixed>
     */
    private function historySection(array $activity, array $crm): array
    {
        $timeline = [];

        foreach ($crm['timeline_snippets'] ?? [] as $snippet) {
            if (! is_array($snippet)) {
                continue;
            }
            $timeline[] = [
                'type' => 'message',
                'direction' => (string) ($snippet['direction'] ?? 'inbound'),
                'body' => (string) ($snippet['body'] ?? ''),
                'at' => $snippet['at'] ?? null,
            ];
        }

        foreach ($crm['facts'] ?? [] as $fact) {
            if (! is_array($fact)) {
                continue;
            }
            $timeline[] = [
                'type' => 'fact',
                'label' => (string) ($fact['label'] ?? ''),
                'body' => (string) ($fact['value'] ?? ''),
                'at' => null,
                'source' => (string) ($fact['source'] ?? 'crm'),
            ];
        }

        foreach ($crm['upcoming_events'] ?? [] as $event) {
            if (! is_array($event)) {
                continue;
            }
            $timeline[] = [
                'type' => 'event',
                'body' => (string) ($event['title'] ?? ''),
                'at' => $event['starts_at'] ?? null,
                'assignee' => $event['assignee'] ?? null,
            ];
        }

        usort($timeline, fn (array $a, array $b): int => strcmp((string) ($b['at'] ?? ''), (string) ($a['at'] ?? '')));

        $messages = is_array($activity['messages'] ?? null) ? $activity['messages'] : [];

        return [
            'key' => 'history',
            'title' => 'История',
            'semantic' => 'context',
            'activity' => array_slice($timeline, 0, 12),
            'fields' => [
                $this->field('Первое сообщение', $this->formatDate($activity['first_message_at'] ?? null), 'crm'),
                $this->field('Последняя активность', $this->formatDate($activity['last_message_at'] ?? null), 'crm'),
                $this->field('Сообщений', (string) ((int) ($messages['total'] ?? 0)), 'crm'),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $crm
     * @return array<string, mixed>
     */
    private function tasksNotesSection(array $crm, string $memoryContent): array
    {
        $fields = [];
        $openTasks = is_array($crm['open_tasks'] ?? null) ? $crm['open_tasks'] : [];

        foreach ($openTasks as $task) {
            if (! is_array($task)) {
                continue;
            }
            $title = trim((string) ($task['title'] ?? ''));
            if ($title === '') {
                continue;
            }
            $status = trim((string) ($task['status'] ?? ''));
            $fields[] = $this->field('Задача', $status !== '' ? "{$title} ({$status})" : $title, 'crm');
        }

        $memoryExcerpt = trim($memoryContent) !== ''
            ? mb_substr(trim($memoryContent), 0, 500)
            : null;

        if ($memoryExcerpt !== null) {
            $fields[] = $this->field('Память', $memoryExcerpt, 'memory');
        }

        if ($fields === []) {
            $fields[] = $this->field('Задачи', 'Нет открытых задач', 'crm');
        }

        return [
            'key' => 'tasks_notes',
            'title' => 'Задачи и заметки',
            'semantic' => 'agreements',
            'fields' => $fields,
            'memory_excerpt' => $memoryExcerpt,
            'memory_url' => route('chats.index'),
        ];
    }

    /**
     * @return array{label: string, value: string, source: string}
     */
    private function field(string $label, string $value, string $source, ?string $code = null): array
    {
        $code ??= ContactFieldCatalog::labelToCodeMap()[$label] ?? null;

        return [
            'code' => $code,
            'label' => $label,
            'value' => $value,
            'source' => $source,
        ];
    }

    private function formatDate(mixed $iso): string
    {
        if (! is_string($iso) || trim($iso) === '') {
            return '—';
        }

        try {
            return \Illuminate\Support\Carbon::parse($iso)->locale('ru')->isoFormat('D MMM YYYY, HH:mm');
        } catch (\Throwable) {
            return $iso;
        }
    }

    /**
     * @param  array<int, int>  $contactIds
     * @return array{content: string, updated_at: string|null, memory_contact_id: int|null}
     */
    private function resolveMemory(array $contactIds, int $primaryContactId): array
    {
        $tenantId = TenantCompany::id();
        $orderedIds = array_values(array_unique([$primaryContactId, ...$contactIds]));

        foreach ($orderedIds as $contactId) {
            $memory = EntityMemory::query()
                ->where('tenant_company_id', $tenantId)
                ->where('subject_type', EntityMemorySubjectType::Contact->value)
                ->where('subject_id', $contactId)
                ->first(['content', 'updated_at']);

            if ($memory === null) {
                $fromFile = trim($this->entityMemory->content(EntityMemorySubjectType::Contact, $contactId));
                if ($fromFile !== '' && ! $this->isDefaultTemplate($fromFile)) {
                    return [
                        'content' => $fromFile,
                        'updated_at' => null,
                        'memory_contact_id' => $contactId,
                    ];
                }

                continue;
            }

            $content = trim((string) $memory->content);
            if ($content === '' || $this->isDefaultTemplate($content)) {
                continue;
            }

            return [
                'content' => $content,
                'updated_at' => $memory->updated_at?->toIso8601String(),
                'memory_contact_id' => $contactId,
            ];
        }

        return [
            'content' => '',
            'updated_at' => null,
            'memory_contact_id' => null,
        ];
    }

    /**
     * Read structured AI-facts for the contact and return them as a labelled array.
     * Falls back to empty array if no facts have been extracted yet.
     *
     * @return list<array{key: string, label: string, value: string, updated_at: string|null}>
     */
    private function resolveAiFacts(int $contactId): array
    {
        try {
            $facts = $this->entityMemory->readAiFacts(EntityMemorySubjectType::Contact, $contactId);
        } catch (Throwable) {
            return [];
        }

        if ($facts === []) {
            return [];
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

        $result = [];
        foreach ($labels as $key => $label) {
            $value = $facts[$key] ?? null;
            if ($value === null || $value === '') {
                continue;
            }
            $result[] = [
                'key'        => $key,
                'label'      => $label,
                'value'      => (string) $value,
                'updated_at' => $facts[$key.'_at'] ?? null,
            ];
        }

        return $result;
    }

    private function isDefaultTemplate(string $content): bool
    {
        $normalized = preg_replace('/\s+/', ' ', mb_strtolower(trim($content))) ?? '';

        return str_contains($normalized, 'имя / как обращаться')
            && str_contains($normalized, 'предпочтения и контекст')
            && mb_strlen(trim($content)) < 400;
    }

    /**
     * @param  list<array{direction: string, body: string|null, at: string|null}>  $snippets
     * @return list<array{label: string, value: string, source: string}>
     */
    private function fieldsFromSnippets(array $snippets): array
    {
        $fields = [];

        foreach ($snippets as $snippet) {
            if (! is_array($snippet) || ($snippet['direction'] ?? '') !== 'inbound') {
                continue;
            }

            $body = trim((string) ($snippet['body'] ?? ''));
            if ($body === '' || mb_strlen($body) > 200) {
                continue;
            }

            $looksLikeAddress = preg_match(
                '/(?:^|[\s,.])(?:ул\.?|улиц|пр\.?|просп|пер\.?|геодез|мкр|район|адрес|дом\s*\d|кв\.?\s*\d)/iu',
                $body,
            ) === 1;

            $looksLikeShortLocation = ! $looksLikeAddress
                && mb_strlen($body) <= 80
                && preg_match('/\d{1,4}/', $body) === 1
                && preg_match('/[а-яёa-z]{4,}/iu', $body) === 1
                && ! preg_match('/^[\d\s+\-()]+$/', $body);

            if (! $looksLikeAddress && ! $looksLikeShortLocation) {
                continue;
            }

            if ($this->fieldIsDuplicate($fields, 'Адрес', $body)) {
                break;
            }

            $fields[] = $this->field('Адрес', $body, 'chat');
            break;
        }

        return $fields;
    }

    /**
     * @param  list<string>  $onlyLabels
     * @return list<array{label: string, value: string, source: string}>
     */
    private function fieldsFromMemory(string $memoryContent, array $onlyLabels = []): array
    {
        $content = trim($memoryContent);
        if ($content === '') {
            return [];
        }

        $patterns = [
            'Адрес' => '/(?:^|\n)\s*(?:[-*#]+\s*)?(?:адрес(?:\s*\/\s*лока(?:ция|ции)?)?|address)\s*:?\s*([^\n]+)/iu',
            'Город' => '/(?:^|\n)\s*(?:[-*#]+\s*)?(?:город|city)\s*:?\s*([^\n]+)/iu',
            'Район' => '/(?:^|\n)\s*(?:[-*#]+\s*)?(?:район|district)\s*:?\s*([^\n]+)/iu',
        ];

        $fields = [];
        foreach ($patterns as $label => $pattern) {
            if ($onlyLabels !== [] && ! in_array($label, $onlyLabels, true)) {
                continue;
            }
            if (! preg_match($pattern, $content, $matches)) {
                continue;
            }
            $value = trim((string) ($matches[1] ?? ''), " \t-");
            if ($value === '' || $value === '-') {
                continue;
            }
            $fields[] = $this->field($label, $value, 'memory');
        }

        return $fields;
    }

    /**
     * @param  array<string, mixed>  $crm
     * @return list<array{label: string, value: string, source: string}>
     */
    private function factsMatching(array $crm, string $pattern): array
    {
        $fields = [];
        foreach ($crm['facts'] ?? [] as $fact) {
            if (! is_array($fact)) {
                continue;
            }
            $label = trim((string) ($fact['label'] ?? ''));
            $value = trim((string) ($fact['value'] ?? ''));
            if ($label === '' || $value === '') {
                continue;
            }
            if (! preg_match($pattern, $label) && ! preg_match($pattern, $value)) {
                continue;
            }
            $fields[] = $this->field($label, $value, (string) ($fact['source'] ?? 'crm'));
        }

        return $fields;
    }

    /**
     * @param  list<array{label: string, value: string, source: string}>  $fields
     */
    private function fieldIsDuplicate(array $fields, string $label, string $value): bool
    {
        return ClientProfileFieldHelper::isDuplicate($fields, [
            'label' => $label,
            'value' => $value,
            'source' => 'crm',
        ]);
    }
}
