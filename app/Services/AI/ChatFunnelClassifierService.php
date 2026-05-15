<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\Chat;
use App\Models\Message;
use App\Services\Funnel\ChatFunnelCatalogBuilder;
use App\Support\OperatorSignature;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

final class ChatFunnelClassifierService
{
    public function __construct(
        private readonly OpenAiChatService $openAi,
        private readonly ChatFunnelCatalogBuilder $catalogBuilder,
    ) {}

    public function classify(Chat $chat, Message $triggerMessage): ?ChatFunnelClassification
    {
        $catalog = $this->catalogBuilder->forChat($chat);
        if ($catalog === []) {
            return null;
        }

        try {
            $raw = $this->openAi->chatJson(
                $this->messages($chat, $triggerMessage, $catalog),
                (float) config('funnel.ai.temperature', 0.15),
                (int) config('funnel.ai.max_tokens', 450),
            );
        } catch (Throwable $e) {
            Log::warning('[funnel-ai] classification failed', [
                'chat_id' => $chat->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        return $this->normalizeAndValidate($chat, $catalog, $raw);
    }

    /**
     * @param  list<array{id: int, name: string, description: string|null, color: string, stages: list<array{id: int, name: string, color: string, position: int}>}>  $catalog
     * @param  array<string, mixed>  $raw
     */
    private function normalizeAndValidate(Chat $chat, array $catalog, array $raw): ?ChatFunnelClassification
    {
        $shouldUpdate = filter_var($raw['should_update'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if (! $shouldUpdate) {
            return null;
        }

        $confidence = (float) ($raw['confidence'] ?? 0);
        $minConf = (float) config('funnel.ai.min_confidence', 0.65);
        if ($confidence < $minConf) {
            return null;
        }

        $funnelId = isset($raw['funnel_id']) ? (int) $raw['funnel_id'] : 0;
        $stageId = isset($raw['funnel_stage_id']) ? (int) $raw['funnel_stage_id'] : 0;
        if ($funnelId <= 0 || $stageId <= 0) {
            return null;
        }

        if (! $this->catalogBuilder->isPairInCatalog($catalog, $funnelId, $stageId)) {
            return null;
        }

        $reason = trim((string) ($raw['reason'] ?? ''));
        if ($reason === '') {
            $reason = 'Этап обновлён по диалогу.';
        }
        $reason = Str::limit($reason, 480, '…');

        if ($this->isRollbackBlocked($chat, $catalog, $funnelId, $stageId, $confidence)) {
            return null;
        }

        if ($chat->funnel_id === $funnelId && $chat->funnel_stage_id === $stageId) {
            return null;
        }

        return new ChatFunnelClassification($funnelId, $stageId, $confidence, $reason);
    }

    /**
     * @param  list<array{id: int, name: string, description: string|null, color: string, stages: list<array{id: int, name: string, color: string, position: int}>}>  $catalog
     */
    private function isRollbackBlocked(Chat $chat, array $catalog, int $newFunnelId, int $newStageId, float $confidence): bool
    {
        if ($chat->funnel_id === null || $chat->funnel_stage_id === null) {
            return false;
        }

        if ((int) $chat->funnel_id !== $newFunnelId) {
            return false;
        }

        $oldIdx = $this->stageIndexInCatalog($catalog, $newFunnelId, (int) $chat->funnel_stage_id);
        $newIdx = $this->stageIndexInCatalog($catalog, $newFunnelId, $newStageId);
        if ($oldIdx === null || $newIdx === null) {
            return false;
        }

        if ($newIdx >= $oldIdx) {
            return false;
        }

        $rollbackMin = (float) config('funnel.ai.rollback_min_confidence', 0.85);

        return $confidence < $rollbackMin;
    }

    /**
     * @param  list<array{id: int, name: string, description: string|null, color: string, stages: list<array{id: int, name: string, color: string, position: int}>}>  $catalog
     */
    private function stageIndexInCatalog(array $catalog, int $funnelId, int $stageId): ?int
    {
        foreach ($catalog as $funnel) {
            if ($funnel['id'] !== $funnelId) {
                continue;
            }
            foreach ($funnel['stages'] as $index => $stage) {
                if ($stage['id'] === $stageId) {
                    return $index;
                }
            }
        }

        return null;
    }

    /**
     * @param  list<array{id: int, name: string, description: string|null, color: string, stages: list<array{id: int, name: string, color: string, position: int}>}>  $catalog
     * @return array<int, array{role: 'system'|'user', content: string}>
     */
    private function messages(Chat $chat, Message $triggerMessage, array $catalog): array
    {
        $limit = (int) config('funnel.ai.history_limit', 16);
        $history = $this->conversationHistory($chat, $limit);
        $catalogJson = json_encode($catalog, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $currentFunnel = $chat->funnel_id;
        $currentStage = $chat->funnel_stage_id;

        $schema = <<<'TXT'
Верни только JSON без Markdown:
{
  "funnel_id": number|null,
  "funnel_stage_id": number|null,
  "confidence": number,
  "should_update": boolean,
  "reason": string
}
TXT;

        $system = <<<PROMPT
Ты аналитик продаж для CRM ChatSwitch. По переписке определи, на каком этапе воронки находится клиент.

Правила:
1. Выбери ровно одну воронку из каталога (funnel_id) и один этап (funnel_stage_id) из её stages. id должны совпадать с каталогом.
2. Если диалог не про продажи или каталог не подходит — верни should_update: false (funnel_id и funnel_stage_id можно null).
3. Не откатывай этап назад в той же воронке без явного отказа клиента, возврата или смены решения (иначе should_update: false).
4. При смене воронки обоснуй в reason.
5. confidence — от 0 до 1, насколько уверен классификатор.
6. reason — кратко по-русски (до ~200 символов).

Текущее состояние чата: funnel_id={$currentFunnel}, funnel_stage_id={$currentStage}.

Каталог воронок (JSON):
{$catalogJson}

{$schema}
PROMPT;

        $triggerBody = Str::limit(OperatorSignature::strip(trim((string) $triggerMessage->body)), 800, '…');

        return [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => "История переписки:\n{$history}\n\nПоследнее входящее сообщение клиента:\n{$triggerBody}"],
        ];
    }

    private function conversationHistory(Chat $chat, int $limit): string
    {
        return $chat->messages()
            ->with('sentByUser:id,name')
            ->whereIn('direction', ['inbound', 'outbound'])
            ->whereNotNull('body')
            ->orderByDesc('message_timestamp')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->reverse()
            ->map(function (Message $message): string {
                $body = Str::limit(OperatorSignature::strip(trim((string) $message->body)), 500, '…');
                $time = optional($message->message_timestamp)->format('Y-m-d H:i') ?? '';

                if ($message->direction === 'outbound') {
                    $name = $message->sentByUser?->name ?: 'Сотрудник';

                    return "[{$time}] {$name}: {$body}";
                }

                return "[{$time}] Клиент: {$body}";
            })
            ->implode("\n");
    }
}
