<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\Chat;
use App\Models\Funnel;
use App\Models\Message;
use Illuminate\Support\Str;

final class AiSimulationService
{
    public function __construct(
        private readonly OpenAiChatService $openAi,
        private readonly KnowledgeContextTextFormatter $knowledge,
    ) {}

    /**
     * @return array{customer_reply: string, funnel_name: string|null, stage_name: string|null, confidence: float, actions: list<string>, manager_needed: bool, reason: string, risks: list<string>, missing_data: list<string>}
     */
    public function simulate(int $companyId, string $message, string $history = ''): array
    {
        $raw = $this->openAi->chatJson(
            $this->simulationMessages($companyId, $message, $history),
            0.2,
            1200,
            new AiUsageOptions('background', $companyId),
        );

        return $this->normalizeSimulation($raw);
    }

    /**
     * @return array{customer_reply: string, funnel_name: string|null, stage_name: string|null, confidence: float, actions: list<string>, manager_needed: bool, reason: string, risks: list<string>, missing_data: list<string>, context: array<string, mixed>}
     */
    public function simulateForChat(Chat $chat, string $message, string $extraHistory = ''): array
    {
        $companyId = (int) ($chat->company_id ?? 0);
        $history = trim($extraHistory);
        $chatHistory = $this->chatHistoryBlock($chat);

        if ($chatHistory !== '') {
            $history = $history !== ''
                ? $chatHistory."\n\n".$history
                : $chatHistory;
        }

        $result = $this->simulate($companyId, $message, $history);
        $chat->loadMissing([
            'funnel:id,name,color',
            'funnelStage:id,name,color,position',
            'funnel.stages' => fn ($query) => $query
                ->where('is_active', true)
                ->orderBy('position')
                ->select(['id', 'funnel_id', 'name', 'color', 'position', 'stage_type']),
        ]);

        $result['context'] = [
            'chat_id' => $chat->id,
            'chat_name' => $chat->chat_name,
            'current_funnel' => $chat->funnel?->name,
            'current_stage' => $chat->funnelStage?->name,
        ];
        $result['stage_preview'] = $this->stagePreview($chat, $result['stage_name'] ?? null);

        return $result;
    }

    private function chatHistoryBlock(Chat $chat): string
    {
        $chat->loadMissing(['funnel:id,name', 'funnelStage:id,name']);

        $lines = $chat->messages()
            ->whereIn('direction', ['inbound', 'outbound'])
            ->whereNotNull('body')
            ->orderByDesc('message_timestamp')
            ->orderByDesc('id')
            ->limit(12)
            ->get(['direction', 'body', 'sender_name'])
            ->reverse()
            ->map(function (Message $message): string {
                $speaker = $message->direction === 'inbound'
                    ? 'Клиент'
                    : (trim((string) $message->sender_name) !== '' ? (string) $message->sender_name : 'Оператор');

                return $speaker.': '.Str::limit(trim((string) $message->body), 240, '...');
            })
            ->values()
            ->all();

        if ($lines === []) {
            return '';
        }

        $context = [];
        if ($chat->funnel?->name) {
            $context[] = 'Текущая воронка: '.$chat->funnel->name;
        }
        if ($chat->funnelStage?->name) {
            $context[] = 'Текущий этап: '.$chat->funnelStage->name;
        }

        $header = $context !== [] ? implode("\n", $context)."\n\n" : '';

        return $header.'История реального чата (последние сообщения):'."\n".implode("\n", $lines);
    }

    /**
     * @return array<int, array{role: 'system'|'user', content: string}>
     */
    private function simulationMessages(int $companyId, string $message, string $history): array
    {
        $catalog = Funnel::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->with([
                'stages' => fn ($query) => $query
                    ->where('is_active', true)
                    ->orderBy('position')
                    ->with('aiRule'),
            ])
            ->orderBy('position')
            ->get()
            ->map(fn (Funnel $funnel): array => [
                'id' => $funnel->id,
                'name' => $funnel->name,
                'description' => $funnel->description,
                'stages' => $funnel->stages->map(fn ($stage): array => [
                    'id' => $stage->id,
                    'name' => $stage->name,
                    'position' => $stage->position,
                    'goal' => $stage->aiRule?->goal,
                    'questions' => $stage->aiRule?->required_questions ?? [],
                    'transition_conditions' => $stage->aiRule?->transition_conditions,
                    'allowed_actions' => $stage->aiRule?->allowed_actions ?? [],
                ])->values()->all(),
            ])
            ->values()
            ->all();

        $knowledgeText = Str::limit(implode("\n", $this->knowledge->knowledgeLines($companyId)), 6000, '...');
        $catalogJson = json_encode($catalog, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $historyBlock = $history !== '' ? "Контекст диалога:\n{$history}\n\n" : '';

        $system = <<<'PROMPT'
Ты — dry-run симулятор AI-воронки Accel. Нужно показать администратору, как AI повёл бы себя с клиентом, но ничего не выполнять.

Верни строго JSON:
{
  "customer_reply": string,
  "funnel_name": string|null,
  "stage_name": string|null,
  "confidence": number,
  "actions": string[],
  "manager_needed": boolean,
  "reason": string,
  "risks": string[],
  "missing_data": string[]
}

Правила:
1. Не повторяй сообщение клиента.
2. Не спрашивай данные, которые уже есть в истории.
3. Не выдумывай цены, сроки, скидки и нестандартные условия.
4. Если данных не хватает, задай один короткий уточняющий вопрос.
5. actions — только человекочитаемые действия: "ответить клиенту", "перевести этап", "создать задачу", "передать менеджеру", "назначить сотрудника", "создать запись".
6. Это симуляция: не пиши клиенту, что действие уже реально выполнено, если его нужно подтвердить.
PROMPT;

        $user = <<<PROMPT
{$historyBlock}Новое тестовое сообщение клиента:
{$message}

База знаний:
{$knowledgeText}

Воронки и AI-правила:
{$catalogJson}
PROMPT;

        return [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $user],
        ];
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array{customer_reply: string, funnel_name: string|null, stage_name: string|null, confidence: float, actions: list<string>, manager_needed: bool, reason: string, risks: list<string>, missing_data: list<string>}
     */
    private function normalizeSimulation(array $raw): array
    {
        return [
            'customer_reply' => Str::limit(trim((string) ($raw['customer_reply'] ?? '')), 1200, '...') ?: 'Не удалось сформировать ответ.',
            'funnel_name' => $this->nullableString($raw['funnel_name'] ?? null),
            'stage_name' => $this->nullableString($raw['stage_name'] ?? null),
            'confidence' => max(0.0, min(1.0, (float) ($raw['confidence'] ?? 0))),
            'actions' => $this->stringList($raw['actions'] ?? []),
            'manager_needed' => filter_var($raw['manager_needed'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'reason' => Str::limit(trim((string) ($raw['reason'] ?? '')), 500, '...') ?: 'AI не указал причину.',
            'risks' => $this->stringList($raw['risks'] ?? []),
            'missing_data' => $this->stringList($raw['missing_data'] ?? []),
        ];
    }

    /**
     * @return array{
     *     stages: list<array{id: int, name: string, color: string|null, stage_type: string|null}>,
     *     from_index: int,
     *     to_index: int|null,
     *     funnel_color: string|null
     * }
     */
    private function stagePreview(Chat $chat, ?string $targetStageName): array
    {
        $stages = $chat->funnel?->stages ?? collect();
        $mapped = $stages
            ->map(fn ($stage): array => [
                'id' => (int) $stage->id,
                'name' => (string) $stage->name,
                'color' => is_string($stage->color) && $stage->color !== '' ? $stage->color : null,
                'stage_type' => is_string($stage->stage_type) && $stage->stage_type !== '' ? $stage->stage_type : null,
            ])
            ->values()
            ->all();

        $fromIndex = -1;
        if ($chat->funnel_stage_id !== null) {
            foreach ($mapped as $index => $stage) {
                if ($stage['id'] === (int) $chat->funnel_stage_id) {
                    $fromIndex = $index;
                    break;
                }
            }
        }

        $toIndex = null;
        $needle = mb_strtolower(trim((string) $targetStageName));
        if ($needle !== '') {
            foreach ($mapped as $index => $stage) {
                if (mb_strtolower($stage['name']) === $needle) {
                    $toIndex = $index;
                    break;
                }
            }
        }

        return [
            'stages' => $mapped,
            'from_index' => $fromIndex,
            'to_index' => $toIndex,
            'funnel_color' => is_string($chat->funnel?->color) && $chat->funnel->color !== ''
                ? $chat->funnel->color
                : null,
        ];
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value !== '' ? Str::limit($value, 160, '...') : null;
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return collect($value)
            ->map(fn (mixed $item): string => Str::limit(trim((string) $item), 160, '...'))
            ->filter()
            ->take(8)
            ->values()
            ->all();
    }
}
