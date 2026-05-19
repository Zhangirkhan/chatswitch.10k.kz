<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\AiOrchestratorRun;
use App\Models\AiResponseLog;
use App\Models\FunnelStage;
use App\Models\Message;
use Illuminate\Support\Collection;

final class MessageAiDecisionService
{
    /**
     * @param  Collection<int, Message>|list<Message>  $messages
     */
    public function attachToMessages(Collection $messages): void
    {
        if ($messages->isEmpty()) {
            return;
        }

        $ids = $messages->pluck('id')->map(fn ($id) => (int) $id)->all();

        $logsByMessageId = AiResponseLog::query()
            ->whereIn('message_id', $ids)
            ->latest('id')
            ->get(['id', 'message_id', 'trigger_message_id', 'mode', 'status', 'metadata', 'error'])
            ->groupBy('message_id');

        $logsByTriggerId = AiResponseLog::query()
            ->whereIn('trigger_message_id', $ids)
            ->latest('id')
            ->get(['id', 'message_id', 'trigger_message_id', 'mode', 'status', 'metadata', 'error'])
            ->groupBy('trigger_message_id');

        $runsByTriggerId = AiOrchestratorRun::query()
            ->whereIn('trigger_message_id', $ids)
            ->latest('id')
            ->get(['id', 'trigger_message_id', 'status', 'reason', 'confidence', 'plan'])
            ->groupBy('trigger_message_id');

        foreach ($messages as $message) {
            $message->setAttribute('ai_decision', $this->resolveForMessage(
                $message,
                $logsByMessageId->get($message->id, collect()),
                $logsByTriggerId->get($message->id, collect()),
                $runsByTriggerId->get($message->id, collect()),
            ));
        }
    }

    /**
     * @param  Collection<int, AiResponseLog>  $logsForMessage
     * @param  Collection<int, AiResponseLog>  $logsForTrigger
     * @param  Collection<int, AiOrchestratorRun>  $runsForTrigger
     * @return array<string, mixed>|null
     */
    private function resolveForMessage(
        Message $message,
        Collection $logsForMessage,
        Collection $logsForTrigger,
        Collection $runsForTrigger,
    ): ?array {
        $isAiOutbound = $message->direction === 'outbound'
            && data_get($message->metadata, 'ai.generated') === true;

        if ($isAiOutbound) {
            $log = $logsForMessage->first() ?? $logsForTrigger->first();
            if ($log !== null) {
                return $this->fromResponseLog($log);
            }
        }

        $run = $runsForTrigger->first();
        if ($run !== null) {
            return $this->fromOrchestratorRun($run);
        }

        if ($message->direction === 'inbound' && $logsForTrigger->isNotEmpty()) {
            return $this->fromResponseLog($logsForTrigger->first());
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function fromResponseLog(AiResponseLog $log): array
    {
        $chips = [];
        $mode = $log->mode === 'draft' ? 'черновик' : 'автоответ';
        $chips[] = ['label' => 'Режим: '.$mode, 'type' => 'mode'];

        if ($log->status === 'failed') {
            $chips[] = ['label' => 'Ошибка генерации', 'type' => 'error'];
        }

        return [
            'source' => 'reply',
            'label' => 'Ответ AI',
            'reason' => is_string($log->error) && $log->error !== ''
                ? $log->error
                : 'AI сгенерировал ответ на основе базы знаний, воронки и истории чата.',
            'chips' => $chips,
            'confidence' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function fromOrchestratorRun(AiOrchestratorRun $run): array
    {
        $chips = [];
        $targetStage = $this->targetStageName($run->plan);
        if ($targetStage !== null) {
            $chips[] = ['label' => 'Этап: '.$targetStage, 'type' => 'stage'];
        }

        $taskTitle = data_get($run->plan, 'task.title');
        if (is_string($taskTitle) && $taskTitle !== '') {
            $chips[] = ['label' => 'Задача: '.$taskTitle, 'type' => 'task'];
        }

        $customerReply = data_get($run->plan, 'customer_reply');
        if (is_string($customerReply) && trim($customerReply) !== '') {
            $chips[] = ['label' => 'Ответ клиенту', 'type' => 'reply'];
        }

        $decision = [
            'source' => 'orchestrator',
            'label' => match ((string) $run->status) {
                AiOrchestratorRun::STATUS_NEEDS_MANAGER => 'Нужен менеджер',
                AiOrchestratorRun::STATUS_FAILED => 'Ошибка AI',
                AiOrchestratorRun::STATUS_SKIPPED => 'AI пропустил шаг',
                default => 'Решение AI по воронке',
            },
            'reason' => $run->reason ?: 'AI проанализировал сообщение клиента и сформировал план действий.',
            'chips' => $chips,
            'confidence' => $run->confidence,
            'plan' => null,
        ];

        if (is_array($run->plan) && $run->plan !== []) {
            $decision['plan'] = $run->plan;
        }

        return $decision;
    }

    /**
     * @param  array<string, mixed>|null  $plan
     */
    private function targetStageName(?array $plan): ?string
    {
        $stageId = (int) data_get($plan, 'target_funnel_stage_id', 0);
        if ($stageId <= 0) {
            return null;
        }

        $stageName = FunnelStage::query()
            ->whereKey($stageId)
            ->value('name');

        return is_string($stageName) && trim($stageName) !== '' ? $stageName : 'Этап #'.$stageId;
    }
}
