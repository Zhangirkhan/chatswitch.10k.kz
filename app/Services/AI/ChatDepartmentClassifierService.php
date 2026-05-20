<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\Chat;
use App\Models\Department;
use App\Models\Message;
use App\Support\ClientMessageHeuristics;
use App\Support\OperatorSignature;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

final class ChatDepartmentClassifierService
{
    public function __construct(
        private readonly OpenAiChatService $openAi,
    ) {}

    public function classify(Chat $chat, Message $triggerMessage): ?ChatDepartmentClassification
    {
        $catalog = $this->departmentCatalog();
        if ($catalog === []) {
            return null;
        }

        if (count($catalog) === 1) {
            return new ChatDepartmentClassification(
                departmentId: (int) $catalog[0]['id'],
                confidence: 0.95,
                reason: 'В компании один активный отдел.',
            );
        }

        try {
            $raw = $this->openAi->chatJson(
                $this->messages($chat, $triggerMessage, $catalog),
                (float) config('funnel.department_routing.temperature', 0.1),
                (int) config('funnel.department_routing.max_tokens', 350),
            );
        } catch (Throwable $e) {
            Log::warning('[department-routing] classification failed', [
                'chat_id' => $chat->id,
                'error' => $e->getMessage(),
            ]);

            return $this->fallbackClassification($catalog, $triggerMessage);
        }

        return $this->normalize($catalog, $raw) ?? $this->fallbackClassification($catalog, $triggerMessage);
    }

    /**
     * @return list<array{id: int, name: string, description: string|null, funnels: list<string>}>
     */
    private function departmentCatalog(): array
    {
        return Department::query()
            ->where('is_active', true)
            ->with(['funnels' => static fn ($q) => $q->where('is_active', true)->orderBy('position')->orderBy('id')])
            ->orderBy('name')
            ->get()
            ->map(static fn (Department $department): array => [
                'id' => $department->id,
                'name' => $department->name,
                'description' => $department->description,
                'funnels' => $department->funnels->pluck('name')->values()->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  list<array{id: int, name: string, description: string|null, funnels: list<string>}>  $catalog
     * @param  array<string, mixed>  $raw
     */
    private function normalize(array $catalog, array $raw): ?ChatDepartmentClassification
    {
        $shouldAssign = filter_var($raw['should_assign'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if (! $shouldAssign) {
            return null;
        }

        $confidence = (float) ($raw['confidence'] ?? 0);
        $minConfidence = (float) config('funnel.department_routing.min_confidence', 0.55);
        if ($confidence < $minConfidence) {
            return null;
        }

        $departmentId = isset($raw['department_id']) ? (int) $raw['department_id'] : 0;
        if ($departmentId <= 0 || ! $this->isInCatalog($catalog, $departmentId)) {
            return null;
        }

        $reason = trim((string) ($raw['reason'] ?? ''));
        if ($reason === '') {
            $reason = 'Отдел выбран по смыслу первого сообщения клиента.';
        }

        return new ChatDepartmentClassification(
            departmentId: $departmentId,
            confidence: $confidence,
            reason: Str::limit($reason, 480, '…'),
        );
    }

    /**
     * @param  list<array{id: int, name: string, description: string|null, funnels: list<string>}>  $catalog
     */
    private function fallbackClassification(array $catalog, Message $triggerMessage): ?ChatDepartmentClassification
    {
        $body = mb_strtolower(OperatorSignature::strip(trim((string) $triggerMessage->body)));

        foreach ($catalog as $department) {
            $name = mb_strtolower((string) $department['name']);
            if ($name !== '' && str_contains($body, $name)) {
                return new ChatDepartmentClassification(
                    departmentId: (int) $department['id'],
                    confidence: 0.7,
                    reason: 'Отдел совпал с упоминанием в сообщении клиента.',
                );
            }
        }

        return new ChatDepartmentClassification(
            departmentId: (int) $catalog[0]['id'],
            confidence: 0.5,
            reason: 'Отдел выбран по умолчанию (первый активный).',
        );
    }

    /**
     * @param  list<array{id: int, name: string, description: string|null, funnels: list<string>}>  $catalog
     */
    private function isInCatalog(array $catalog, int $departmentId): bool
    {
        foreach ($catalog as $department) {
            if ((int) $department['id'] === $departmentId) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array{id: int, name: string, description: string|null, funnels: list<string>}>  $catalog
     * @return array<int, array{role: 'system'|'user', content: string}>
     */
    private function messages(Chat $chat, Message $triggerMessage, array $catalog): array
    {
        $catalogJson = json_encode($catalog, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $triggerBody = Str::limit(OperatorSignature::strip(trim((string) $triggerMessage->body)), 800, '…');
        $greetingHint = ClientMessageHeuristics::isShortGreetingOnly((string) $triggerMessage->body)
            ? 'Клиент, скорее всего, только поздоровался — выбери отдел первичного приёма (продажи, консультации).'
            : 'Учти тему запроса: замер, монтаж, доставка, цены, запись, рекламации.';

        $schema = <<<'TXT'
Верни только JSON без Markdown:
{
  "department_id": number|null,
  "confidence": number,
  "should_assign": boolean,
  "reason": string
}
TXT;

        $system = <<<PROMPT
Ты маршрутизатор входящих WhatsApp-обращений в CRM ChatSwitch. По первому сообщению клиента выбери один отдел, который должен вести диалог.

Правила:
1. department_id — только из каталога ниже.
2. Если сообщение — только приветствие без запроса, назначь отдел первичного приёма/продаж (если есть в каталоге).
3. Если запрос явно про исполнение (монтаж, доставка, замер на объекте) — отдел операций/замерщиков/исполнителей.
4. Если не уверен — should_assign: true и отдел с наиболее общим профилем (продажи), confidence ниже.
5. reason — кратко по-русски.

{$greetingHint}

Каталог отделов (JSON):
{$catalogJson}

{$schema}
PROMPT;

        return [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => "Чат: {$chat->chat_name}\nПоследнее сообщение клиента:\n{$triggerBody}"],
        ];
    }
}
