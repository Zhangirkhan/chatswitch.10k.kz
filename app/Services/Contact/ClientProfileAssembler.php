<?php

declare(strict_types=1);

namespace App\Services\Contact;

use App\Enums\EntityMemorySubjectType;
use App\Models\Contact;
use App\Models\EntityMemory;
use App\Models\User;
use App\Services\Memory\EntityMemoryService;
use App\Support\TenantCompany;

final class ClientProfileAssembler
{
    public function __construct(
        private readonly ContactCardAssembler $cardAssembler,
        private readonly ContactBucketResolver $buckets,
        private readonly EntityMemoryService $entityMemory,
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

        $contactIds = $this->buckets->bucketIds($contact);
        $memory = $this->resolveMemory($contactIds, $contact->id);

        return [
            'contact_id' => $contact->id,
            'display_name' => (string) ($identity['display_name'] ?? 'Без имени'),
            'sections' => [
                $this->basicSection($contact, $identity, $crm),
                $this->contactsSection($identity, $crm, $channels),
                $this->financeSection(),
                $this->b2bSection($identity, $crm),
                $this->historySection($activity, $crm),
                $this->tasksNotesSection($crm, $memory['content']),
            ],
            'memory' => $memory,
        ];
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
     * @return array<string, mixed>
     */
    private function contactsSection(array $identity, array $crm, array $channels): array
    {
        $fields = [];

        $phone = trim((string) ($identity['phone_number'] ?? ''));
        if ($phone !== '') {
            $fields[] = $this->field('Телефон', $phone, 'crm');
        }

        $channelLabels = collect($channels)
            ->map(function (array $row): string {
                $label = trim((string) ($row['session_label'] ?? ''));
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
            $fields[] = $this->field('Каналы WhatsApp', implode('; ', $channelLabels), 'crm');
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
            $phone = trim((string) ($identity['phone_number'] ?? ''));
            if ($displayName !== '') {
                $fields[] = $this->field('Клиент', $displayName, 'crm');
            }
            if ($phone !== '') {
                $fields[] = $this->field('Телефон', $phone, 'crm');
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
    private function field(string $label, string $value, string $source): array
    {
        return [
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

    private function isDefaultTemplate(string $content): bool
    {
        $normalized = preg_replace('/\s+/', ' ', mb_strtolower(trim($content))) ?? '';

        return str_contains($normalized, 'имя / как обращаться')
            && str_contains($normalized, 'предпочтения и контекст')
            && mb_strlen(trim($content)) < 400;
    }
}
