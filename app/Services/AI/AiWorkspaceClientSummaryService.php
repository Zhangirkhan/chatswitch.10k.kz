<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Enums\EntityMemorySubjectType;
use App\Models\Contact;
use App\Models\EntityMemory;
use App\Models\User;
use App\Services\Contact\ContactBucketResolver;
use App\Services\Contact\ContactCardAssembler;
use App\Services\Memory\EntityMemoryService;
use App\Support\TenantCompany;
use Illuminate\Support\Facades\Log;
use Throwable;

final class AiWorkspaceClientSummaryService
{
    public function __construct(
        private readonly ContactCardAssembler $cardAssembler,
        private readonly ContactBucketResolver $bucketResolver,
        private readonly EntityMemoryService $entityMemory,
        private readonly OpenAiChatService $openAi,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function build(User $user, Contact $contact, ?int $preferredChatId = null): ?array
    {
        if (! $user->can('view', $contact)) {
            abort(403);
        }

        $card = $this->cardAssembler->build($user, $contact, $preferredChatId);
        $contactIds = $this->bucketResolver->bucketIds($contact);
        $chatIds = collect($card['channels'] ?? [])
            ->pluck('chat_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->all();

        $memoryPayload = $this->resolveMemory($contactIds, (int) $contact->id);
        $snippets = $this->cardAssembler->recentMessageSnippets($chatIds);

        $ai = $this->synthesizeSummary(
            $card,
            $memoryPayload['content'],
            $snippets,
            $user->company_id,
        );

        $crm = is_array($card['crm'] ?? null) ? $card['crm'] : [];
        $identity = is_array($card['identity'] ?? null) ? $card['identity'] : [];
        $companies = collect($crm['companies'] ?? [])
            ->map(fn (array $row) => (string) ($row['name'] ?? ''))
            ->filter()
            ->values()
            ->all();

        $deal = is_array($crm['deal'] ?? null) ? $crm['deal'] : null;
        $primaryChatId = is_array($deal) ? ($deal['chat_id'] ?? null) : null;
        if ($primaryChatId === null && $chatIds !== []) {
            $primaryChatId = $chatIds[0];
        }

        return [
            'contact_id' => (int) $contact->id,
            'identity' => [
                'display_name' => (string) ($identity['display_name'] ?? 'Без имени'),
                'phone' => $identity['phone_number'] ?? null,
                'avatar' => $identity['profile_picture_url'] ?? null,
                'companies' => $companies,
            ],
            'crm' => [
                'deal' => $deal,
                'upcoming_events_count' => count($crm['upcoming_events'] ?? []),
                'open_tasks_count' => count($crm['open_tasks'] ?? []),
            ],
            'memory_updated_at' => $memoryPayload['updated_at'],
            'ai' => $ai,
            'primary_chat_id' => $primaryChatId !== null ? (int) $primaryChatId : null,
            'candidate_contact_ids' => $contactIds,
        ];
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

    /**
     * @param  array<string, mixed>  $card
     * @param  list<array{direction: string, body: string|null, at: string|null}>  $snippets
     * @return array{headline: string, sections: list<array{title: string, body: string}>, confidence: string}
     */
    private function synthesizeSummary(
        array $card,
        string $memoryContent,
        array $snippets,
        ?int $companyId,
    ): array {
        $identity = is_array($card['identity'] ?? null) ? $card['identity'] : [];
        $activity = is_array($card['activity'] ?? null) ? $card['activity'] : [];
        $crm = is_array($card['crm'] ?? null) ? $card['crm'] : [];

        $context = $this->buildContextBlock($identity, $activity, $crm, $memoryContent, $snippets);

        if (trim($context) === '') {
            return $this->fallbackAiPayload('Недостаточно данных о клиенте.');
        }

        try {
            $decoded = $this->openAi->chatJson(
                [
                    [
                        'role' => 'system',
                        'content' => <<<'PROMPT'
Ты собираешь сводку по клиенту для менеджера CRM Accel.
Верни JSON:
{
  "headline": "одна строка — суть клиента",
  "sections": [
    { "title": "Кто это", "body": "..." },
    { "title": "Предпочтения", "body": "..." },
    { "title": "Контекст и локация", "body": "..." },
    { "title": "Договорённости", "body": "..." },
    { "title": "Сделка и следующий шаг", "body": "..." }
  ],
  "confidence": "high|medium|low"
}

Правила:
- Используй ТОЛЬКО факты из блока «Данные». Не выдумывай адрес, предпочтения, суммы.
- Если данных нет для секции — напиши «Нет данных» (1 короткая фраза).
- confidence=high только если есть memory.md или несколько явных фактов из переписки.
- confidence=low если почти нет данных.
- Пиши по-русски, кратко (1–3 предложения на секцию).
PROMPT,
                    ],
                    [
                        'role' => 'user',
                        'content' => "Данные:\n{$context}",
                    ],
                ],
                0.2,
                1200,
                new AiUsageOptions('workspace_client_summary', $companyId),
            );

            return $this->normalizeAiPayload($decoded);
        } catch (Throwable $e) {
            Log::warning('[ai-workspace] client summary synthesis failed', [
                'error' => $e->getMessage(),
            ]);

            return $this->fallbackAiPayload('Не удалось сгенерировать AI-сводку. Смотрите данные CRM ниже.');
        }
    }

    /**
     * @param  array<string, mixed>  $identity
     * @param  array<string, mixed>  $activity
     * @param  array<string, mixed>  $crm
     * @param  list<array{direction: string, body: string|null, at: string|null}>  $snippets
     */
    private function buildContextBlock(
        array $identity,
        array $activity,
        array $crm,
        string $memoryContent,
        array $snippets,
    ): string {
        $lines = [];

        $lines[] = 'Имя: '.($identity['display_name'] ?? '—');
        $lines[] = 'Телефон: '.($identity['phone_number'] ?? '—');
        if (! empty($identity['possible_names'])) {
            $lines[] = 'Варианты имён: '.implode(', ', array_slice((array) $identity['possible_names'], 0, 8));
        }

        $companies = collect($crm['companies'] ?? [])
            ->map(function (array $row): string {
                $name = (string) ($row['name'] ?? '');
                $position = trim((string) ($row['position'] ?? ''));

                return $position !== '' ? "{$name} ({$position})" : $name;
            })
            ->filter()
            ->values()
            ->all();
        if ($companies !== []) {
            $lines[] = 'Компании: '.implode('; ', $companies);
        }

        $deal = is_array($crm['deal'] ?? null) ? $crm['deal'] : null;
        if ($deal !== null) {
            $funnel = is_array($deal['funnel'] ?? null) ? $deal['funnel'] : null;
            $stage = is_array($deal['stage'] ?? null) ? $deal['stage'] : null;
            $lines[] = 'Воронка: '.($funnel['name'] ?? '—').', этап: '.($stage['name'] ?? '—');
            if (! empty($deal['ai_orchestrator_summary'])) {
                $lines[] = 'AI по сделке: '.$deal['ai_orchestrator_summary'];
            }
        }

        foreach ($crm['facts'] ?? [] as $fact) {
            if (! is_array($fact)) {
                continue;
            }
            $label = trim((string) ($fact['label'] ?? ''));
            $value = trim((string) ($fact['value'] ?? ''));
            if ($label !== '' && $value !== '') {
                $lines[] = "{$label}: {$value}";
            }
        }

        $messages = is_array($activity['messages'] ?? null) ? $activity['messages'] : [];
        $lines[] = 'Сообщений всего: '.(int) ($messages['total'] ?? 0)
            .', от клиента: '.(int) ($messages['inbound'] ?? 0);

        if ($memoryContent !== '') {
            $lines[] = "Память (memory.md):\n{$memoryContent}";
        }

        if ($snippets !== []) {
            $lines[] = 'Фрагменты переписки:';
            foreach ($snippets as $snippet) {
                $dir = $snippet['direction'] === 'inbound' ? 'клиент' : 'оператор';
                $body = trim((string) ($snippet['body'] ?? ''));
                if ($body !== '') {
                    $lines[] = "- [{$dir}] {$body}";
                }
            }
        }

        $events = $crm['upcoming_events'] ?? [];
        if (is_array($events) && $events !== []) {
            $lines[] = 'Ближайшие записи: '.count($events);
        }

        $tasks = $crm['open_tasks'] ?? [];
        if (is_array($tasks) && $tasks !== []) {
            $lines[] = 'Открытые задачи: '.count($tasks);
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $decoded
     * @return array{headline: string, sections: list<array{title: string, body: string}>, confidence: string}
     */
    private function normalizeAiPayload(array $decoded): array
    {
        $headline = trim((string) ($decoded['headline'] ?? ''));
        $confidence = (string) ($decoded['confidence'] ?? 'medium');
        if (! in_array($confidence, ['high', 'medium', 'low'], true)) {
            $confidence = 'medium';
        }

        $sections = [];
        foreach ($decoded['sections'] ?? [] as $section) {
            if (! is_array($section)) {
                continue;
            }
            $title = trim((string) ($section['title'] ?? ''));
            $body = trim((string) ($section['body'] ?? ''));
            if ($title === '' || $body === '') {
                continue;
            }
            $sections[] = ['title' => $title, 'body' => $body];
        }

        if ($headline === '' && $sections === []) {
            return $this->fallbackAiPayload('Нет данных для сводки.');
        }

        return [
            'headline' => $headline !== '' ? $headline : 'Сводка по клиенту',
            'sections' => $sections,
            'confidence' => $confidence,
        ];
    }

    /**
     * @return array{headline: string, sections: list<array{title: string, body: string}>, confidence: string}
     */
    private function fallbackAiPayload(string $message): array
    {
        return [
            'headline' => $message,
            'sections' => [],
            'confidence' => 'low',
        ];
    }
}
