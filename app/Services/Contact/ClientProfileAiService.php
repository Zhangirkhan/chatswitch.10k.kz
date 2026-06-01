<?php

declare(strict_types=1);

namespace App\Services\Contact;

use App\Models\Contact;
use App\Models\User;
use App\Services\AI\AiUsageOptions;
use App\Services\AI\OpenAiChatService;
use App\Support\ClientProfileFieldHelper;
use Illuminate\Support\Facades\Log;
use Throwable;

final class ClientProfileAiService
{
    public function __construct(
        private readonly ContactCardAssembler $cardAssembler,
        private readonly OpenAiChatService $openAi,
    ) {}

    /**
     * @param  array<string, mixed>  $profile
     * @return array<string, mixed>
     */
    public function enrich(User $user, Contact $contact, array $profile, ?int $preferredChatId = null): array
    {
        $card = $this->cardAssembler->build($user, $contact, $preferredChatId);
        $memory = is_array($profile['memory'] ?? null) ? $profile['memory'] : [];
        $memoryContent = trim((string) ($memory['content'] ?? ''));

        $chatIds = collect($card['channels'] ?? [])
            ->pluck('chat_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->all();

        $snippets = $this->cardAssembler->recentMessageSnippets($chatIds, 4);
        $aiFields = $this->synthesizeFields($card, $memoryContent, $snippets, $user->company_id);

        if ($aiFields === []) {
            return $profile;
        }

        $sections = is_array($profile['sections'] ?? null) ? $profile['sections'] : [];
        foreach ($sections as $index => $section) {
            if (! is_array($section)) {
                continue;
            }
            $key = (string) ($section['key'] ?? '');
            if ($key === 'finance') {
                continue;
            }
            $extra = $aiFields[$key] ?? [];
            if (! is_array($extra) || $extra === []) {
                continue;
            }
            $existingFields = is_array($section['fields'] ?? null) ? $section['fields'] : [];
            $sections[$index]['fields'] = ClientProfileFieldHelper::mergeUnique($existingFields, $extra);
        }

        $profile['sections'] = $sections;
        $profile['ai_enriched'] = true;

        return $profile;
    }

    /**
     * @param  array<string, mixed>  $card
     * @param  list<array{direction: string, body: string|null, at: string|null}>  $snippets
     * @return array<string, list<array{label: string, value: string, source: string}>>
     */
    private function synthesizeFields(
        array $card,
        string $memoryContent,
        array $snippets,
        ?int $companyId,
    ): array {
        $context = $this->buildContextBlock($card, $memoryContent, $snippets);

        if (trim($context) === '') {
            return [];
        }

        try {
            $decoded = $this->openAi->chatJson(
                [
                    [
                        'role' => 'system',
                        'content' => <<<'PROMPT'
Ты дополняешь профиль клиента CRM Accel пробелами из переписки и памяти.
Верни JSON:
{
  "basic": [{"label": "...", "value": "...", "source": "ai|chat|memory"}],
  "contacts": [...],
  "b2b": [...],
  "history": [...],
  "tasks_notes": [...]
}

Правила:
- НЕ заполняй finance — его нет в схеме.
- Не выдумывай суммы, реквизиты, LTV, заказы.
- Каждое поле: label (короткий), value (1–2 предложения), source = ai|chat|memory.
- Добавляй только поля с реальной опорой в данных (теги, предпочтительный канал, LPR, отрасль, UTM/источник, нюансы общения).
- НЕ дублируй уже известные поля: имя, этап воронки, адрес, телефон, ID контакта.
- Если данных нет — верни пустой массив для секции.
- Пиши по-русски.
PROMPT,
                    ],
                    [
                        'role' => 'user',
                        'content' => "Данные:\n{$context}",
                    ],
                ],
                0.2,
                900,
                new AiUsageOptions('client_profile_ai', $companyId),
            );

            return $this->normalizeFields($decoded);
        } catch (Throwable $e) {
            Log::warning('[client-profile] AI enrichment failed', [
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * @param  array<string, mixed>  $card
     * @param  list<array{direction: string, body: string|null, at: string|null}>  $snippets
     */
    private function buildContextBlock(array $card, string $memoryContent, array $snippets): string
    {
        $identity = is_array($card['identity'] ?? null) ? $card['identity'] : [];
        $crm = is_array($card['crm'] ?? null) ? $card['crm'] : [];
        $lines = [];

        $lines[] = 'Имя: '.($identity['display_name'] ?? '—');
        $lines[] = 'Телефон: '.($identity['phone_number'] ?? '—');

        foreach ($crm['companies'] ?? [] as $company) {
            if (! is_array($company)) {
                continue;
            }
            $lines[] = 'Компания: '.($company['name'] ?? '').' '.($company['position'] ?? '');
        }

        if ($memoryContent !== '') {
            $lines[] = "Память:\n{$memoryContent}";
        }

        if ($snippets !== []) {
            $lines[] = 'Переписка:';
            foreach ($snippets as $snippet) {
                $dir = ($snippet['direction'] ?? '') === 'inbound' ? 'клиент' : 'менеджер';
                $lines[] = "- [{$dir}] ".mb_substr((string) ($snippet['body'] ?? ''), 0, 200);
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @return array<string, list<array{label: string, value: string, source: string}>>
     */
    private function normalizeFields(mixed $decoded): array
    {
        if (! is_array($decoded)) {
            return [];
        }

        $result = [];
        foreach (['basic', 'contacts', 'b2b', 'history', 'tasks_notes'] as $key) {
            $rows = $decoded[$key] ?? [];
            if (! is_array($rows)) {
                continue;
            }
            $fields = [];
            foreach ($rows as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $label = trim((string) ($row['label'] ?? ''));
                $value = trim((string) ($row['value'] ?? ''));
                if ($label === '' || $value === '') {
                    continue;
                }
                $source = in_array($row['source'] ?? '', ['ai', 'chat', 'memory'], true)
                    ? (string) $row['source']
                    : 'ai';
                $fields[] = [
                    'label' => $label,
                    'value' => $value,
                    'source' => $source,
                ];
            }
            if ($fields !== []) {
                $result[$key] = $fields;
            }
        }

        return $result;
    }
}
