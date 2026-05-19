<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Events\ChatAiOrchestratorUpdated;
use App\Jobs\GenerateAiReplyJob;
use App\Models\AiOrchestratorRun;
use App\Models\Chat;
use App\Models\FunnelAiScenario;
use App\Models\FunnelStageAiRule;
use App\Models\Message;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\Calendar\CalendarAvailabilityService;
use App\Services\Funnel\FunnelStageTransitionGuard;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

final class AiFunnelOrchestratorService
{
    public function __construct(
        private readonly AiFunnelPlannerService $planner,
        private readonly AiFunnelActionExecutor $executor,
        private readonly CalendarAvailabilityService $availability,
        private readonly KnowledgeContextRepository $knowledge,
        private readonly FunnelStageTransitionGuard $stageTransitionGuard,
    ) {}

    public function run(int $chatId, int $triggerMessageId): void
    {
        $chat = Chat::query()
            ->with([
                'aiResponder',
                'assignments.user',
                'funnel.aiScenario.fallbackManager',
                'funnel.aiScenario.fallbackDepartment',
                'funnel.stages',
                'funnelStage.aiRule.assigneeDepartment.users',
            ])
            ->whereKey($chatId)
            ->first();
        $trigger = Message::query()->whereKey($triggerMessageId)->first();

        if ($chat === null || $trigger === null || ! $this->canRun($chat, $trigger)) {
            return;
        }

        $scenario = $chat->funnel?->aiScenario;
        if (! $scenario instanceof FunnelAiScenario || ! $scenario->enabled) {
            return;
        }

        $rule = $chat->funnelStage?->aiRule;
        $run = AiOrchestratorRun::query()->firstOrCreate(
            ['chat_id' => $chat->id, 'trigger_message_id' => $trigger->id],
            [
                'company_id' => $chat->company_id,
                'funnel_id' => $chat->funnel_id,
                'funnel_stage_id' => $chat->funnel_stage_id,
                'status' => AiOrchestratorRun::STATUS_PENDING,
            ],
        );

        if (in_array($run->status, [AiOrchestratorRun::STATUS_COMPLETED, AiOrchestratorRun::STATUS_NEEDS_MANAGER], true)) {
            if (! $this->shouldRetryCatalogInquiryRun($chat, $run, $trigger)) {
                return;
            }

            $run->forceFill([
                'status' => AiOrchestratorRun::STATUS_PENDING,
                'completed_at' => null,
                'error' => null,
            ])->save();
        }

        $actor = $this->actor($chat, $scenario);
        if (! $actor instanceof User) {
            $this->finishSkipped($run, $chat, 'Нет сотрудника, от имени которого может работать AI-оркестратор.');

            return;
        }

        $candidateAssignees = $this->candidateAssignees($chat, $scenario, $rule);
        $availableSlots = $this->availableSlots($candidateAssignees, (int) $scenario->booking_horizon_days);

        try {
            $run->forceFill([
                'status' => AiOrchestratorRun::STATUS_RUNNING,
                'started_at' => now(),
                'error' => null,
            ])->save();

            [$plan, $context] = $this->planner->plan($chat, $trigger, $actor, $scenario, $rule, $candidateAssignees, $availableSlots);
            $minConfidence = (float) config('funnel.orchestrator.min_confidence', 0.7);
            $plan = $this->normalizePlan($chat, $trigger, $plan);
            if ($plan->confidence < $minConfidence) {
                $plan = $this->lowConfidencePlan($chat, $trigger, $rule, $plan, $minConfidence);
            }
            $plan = $this->finalizeCustomerFacingPlan($chat, $trigger, $plan);
            $plan = $this->applyAutomationPolicy($chat, $scenario, $rule, $plan);
            $plan = $this->sanitizeStageTransition($chat, $plan);

            $actions = $this->executor->execute($run, $chat, $trigger, $actor, $scenario, $rule, $plan);
            $status = $plan->requiresManagerAttention
                ? AiOrchestratorRun::STATUS_NEEDS_MANAGER
                : AiOrchestratorRun::STATUS_COMPLETED;

            $run->forceFill([
                'status' => $status,
                'confidence' => $plan->confidence,
                'reason' => $plan->reason,
                'context' => $context,
                'plan' => [
                    ...$plan->toArray(),
                    'actions' => $actions,
                ],
                'completed_at' => now(),
            ])->save();

            $chat->forceFill([
                'ai_orchestrator_status' => $status,
                'ai_orchestrator_last_run_id' => $run->id,
                'ai_orchestrator_last_action_at' => now(),
                'ai_orchestrator_last_summary' => mb_substr($plan->reason, 0, 500),
            ])->save();
            $this->broadcastStatus($chat);
            $this->dispatchFallbackReplyIfNeeded($chat, $trigger, $plan, $status, $actions);
        } catch (Throwable $e) {
            $run->forceFill([
                'status' => AiOrchestratorRun::STATUS_FAILED,
                'error' => mb_substr($e->getMessage(), 0, 2000),
                'completed_at' => now(),
            ])->save();

            $chat->forceFill([
                'ai_orchestrator_status' => AiOrchestratorRun::STATUS_FAILED,
                'ai_orchestrator_last_run_id' => $run->id,
                'ai_orchestrator_last_action_at' => now(),
                'ai_orchestrator_last_summary' => mb_substr($e->getMessage(), 0, 500),
            ])->save();
            $this->broadcastStatus($chat);

            Log::warning('[ai-orchestrator] failed', [
                'chat_id' => $chat->id,
                'trigger_message_id' => $trigger->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    private function applyAutomationPolicy(
        Chat $chat,
        FunnelAiScenario $scenario,
        ?FunnelStageAiRule $rule,
        AiFunnelOrchestratorPlan $plan,
    ): AiFunnelOrchestratorPlan {
        $needsManagerConfirm = $scenario->manager_confirmation_required
            || ($rule?->require_manager_confirmation ?? false);

        if ($needsManagerConfirm && ($plan->appointment !== null || $plan->targetFunnelStageId !== null)) {
            return new AiFunnelOrchestratorPlan(
                customerReply: $plan->customerReply,
                targetFunnelStageId: null,
                appointment: null,
                assigneeUserId: $plan->assigneeUserId,
                managerNote: $plan->managerNote ?? 'Требуется подтверждение менеджера перед записью или сменой этапа.',
                task: $plan->task ?? [
                    'title' => 'Подтвердить действие AI',
                    'body' => 'Клиент ждёт ответа. AI предложил запись или смену этапа — нужно подтверждение менеджера.',
                ],
                requiresManagerAttention: true,
                confidence: $plan->confidence,
                reason: $plan->reason.' (ожидает подтверждения менеджера)',
            );
        }

        return $plan;
    }

    private function sanitizeStageTransition(Chat $chat, AiFunnelOrchestratorPlan $plan): AiFunnelOrchestratorPlan
    {
        if ($plan->targetFunnelStageId === null || $chat->funnel_id === null) {
            return $plan;
        }

        $funnelId = (int) $chat->funnel_id;
        $stageId = (int) $plan->targetFunnelStageId;
        $reject = $this->stageTransitionGuard->rejectReason($chat, $funnelId, $stageId, $plan->confidence);
        if ($reject === null) {
            return $plan;
        }

        return new AiFunnelOrchestratorPlan(
            customerReply: $plan->customerReply,
            targetFunnelStageId: null,
            appointment: $plan->appointment,
            assigneeUserId: $plan->assigneeUserId,
            managerNote: $plan->managerNote,
            task: $plan->task,
            requiresManagerAttention: $plan->requiresManagerAttention,
            confidence: $plan->confidence,
            reason: $plan->reason.' (смена этапа отклонена: '.$reject.')',
        );
    }

    /**
     * @param  array<string, mixed>  $actions
     */
    private function dispatchFallbackReplyIfNeeded(
        Chat $chat,
        Message $trigger,
        AiFunnelOrchestratorPlan $plan,
        string $status,
        array $actions,
    ): void {
        if (! $chat->ai_enabled) {
            return;
        }

        $replied = isset($actions['reply_customer']) && ! (($actions['reply_customer']['skipped'] ?? false) === true);
        if ($replied || ($plan->customerReply !== null && trim($plan->customerReply) !== '')) {
            return;
        }

        if ($status === AiOrchestratorRun::STATUS_NEEDS_MANAGER
            || ($status === AiOrchestratorRun::STATUS_COMPLETED && $plan->customerReply === null)) {
            GenerateAiReplyJob::dispatch($chat->id, $trigger->id);
        }
    }

    private function canRun(Chat $chat, Message $trigger): bool
    {
        if ($chat->is_group || ! $chat->funnel_tracking_enabled || $chat->funnel_stage_locked) {
            return false;
        }
        if ($trigger->direction !== 'inbound' || (int) $trigger->chat_id !== (int) $chat->id) {
            return false;
        }

        $latestInboundId = Message::query()
            ->where('chat_id', $chat->id)
            ->where('direction', 'inbound')
            ->latest('message_timestamp')
            ->latest('id')
            ->value('id');

        return (int) $latestInboundId === (int) $trigger->id;
    }

    private function lowConfidencePlan(
        Chat $chat,
        Message $trigger,
        ?FunnelStageAiRule $rule,
        AiFunnelOrchestratorPlan $plan,
        float $minConfidence,
    ): AiFunnelOrchestratorPlan {
        if ($this->shouldOfferCatalog($chat, $trigger)) {
            return $this->normalizeCatalogInquiry($chat, $trigger, $plan);
        }

        $questionPlan = $this->questionFallbackPlan($chat, $trigger, $rule, $plan, $minConfidence);
        if ($questionPlan instanceof AiFunnelOrchestratorPlan) {
            return $questionPlan;
        }

        return new AiFunnelOrchestratorPlan(
            customerReply: null,
            targetFunnelStageId: null,
            appointment: null,
            assigneeUserId: null,
            managerNote: 'AI-оркестратор не уверен в следующем шаге: '.$plan->reason,
            task: [
                'title' => 'Проверить диалог клиента',
                'body' => 'AI-оркестратор не уверен в следующем шаге. Причина: '.$plan->reason,
            ],
            requiresManagerAttention: true,
            confidence: $plan->confidence,
            reason: $plan->reason,
        );
    }

    private function normalizePlan(Chat $chat, Message $trigger, AiFunnelOrchestratorPlan $plan): AiFunnelOrchestratorPlan
    {
        $plan = $this->normalizeCompletionSignal($chat, $trigger, $plan);
        $plan = $this->normalizeDeliveryScheduling($chat, $trigger, $plan);
        $plan = $this->normalizeEchoReply($chat, $trigger, $plan);
        $plan = $this->normalizePaymentStage($chat, $trigger, $plan);
        $plan = $this->normalizeMeasurementHandoff($chat, $plan);

        if ($this->isFirstStageProductInterest($chat, $trigger)) {
            $reply = mb_strtolower((string) $plan->customerReply);
            if (str_contains($reply, 'какое издел') || str_contains($reply, 'тип издел')) {
                $plan = new AiFunnelOrchestratorPlan(
                    customerReply: $this->productInterestReply($trigger),
                    targetFunnelStageId: $this->nextStageId($chat) ?? $plan->targetFunnelStageId,
                    appointment: null,
                    assigneeUserId: null,
                    managerNote: null,
                    task: null,
                    requiresManagerAttention: false,
                    confidence: max($plan->confidence, 0.85),
                    reason: 'Клиент уже указал изделие, AI уточняет параметры.',
                );
            }
        }

        return $this->finalizeCustomerFacingPlan($chat, $trigger, $plan);
    }

    private function finalizeCustomerFacingPlan(Chat $chat, Message $trigger, AiFunnelOrchestratorPlan $plan): AiFunnelOrchestratorPlan
    {
        $plan = $this->normalizeCatalogInquiry($chat, $trigger, $plan);
        $plan = $this->normalizeClientGreeting($chat, $trigger, $plan);
        $plan = $this->normalizeRepeatedQuestion($chat, $trigger, $plan);

        return $this->recoverUnhelpfulPlan($chat, $trigger, $plan);
    }

    private function shouldRetryCatalogInquiryRun(Chat $chat, AiOrchestratorRun $run, Message $trigger): bool
    {
        if ($run->status !== AiOrchestratorRun::STATUS_NEEDS_MANAGER) {
            return false;
        }

        if (! $this->isCatalogInquiry((string) $trigger->body)
            && ! $this->chatHasRecentCatalogInquiry($chat, (int) $trigger->id)) {
            return false;
        }

        $reason = mb_strtolower((string) $run->reason);

        return str_contains($reason, 'повтор')
            || str_contains($reason, 'уточняющ')
            || str_contains($reason, 'не уверен')
            || str_contains($reason, 'эхо');
    }

    private function normalizeCatalogInquiry(Chat $chat, Message $trigger, AiFunnelOrchestratorPlan $plan): AiFunnelOrchestratorPlan
    {
        if (! $this->shouldOfferCatalog($chat, $trigger)) {
            return $plan;
        }

        $companyId = (int) ($chat->company_id ?? 0);
        if ($companyId <= 0) {
            return $plan;
        }

        $shouldListCatalog = $plan->customerReply === null
            || $this->isGenericStubReply($plan->customerReply)
            || $this->looksLikeQuestion((string) $plan->customerReply)
            || $plan->requiresManagerAttention;

        if (! $shouldListCatalog) {
            return $plan;
        }

        return $this->makeCatalogPlan(
            $chat,
            $trigger,
            $plan,
            'Клиент спросил об ассортименте — AI перечислил позиции из базы знаний.',
        );
    }

    private function recoverUnhelpfulPlan(Chat $chat, Message $trigger, AiFunnelOrchestratorPlan $plan): AiFunnelOrchestratorPlan
    {
        $companyId = (int) ($chat->company_id ?? 0);
        if ($companyId <= 0 || ! $this->hasCatalogProducts($companyId)) {
            return $plan;
        }

        if (! $this->shouldOfferCatalog($chat, $trigger)) {
            return $plan;
        }

        $needsRecovery = $plan->customerReply === null
            || $this->isGenericStubReply($plan->customerReply)
            || $plan->requiresManagerAttention
            || ($this->looksLikeQuestion((string) $plan->customerReply) && $this->isRepeatStopReason((string) $plan->reason));

        if (! $needsRecovery) {
            return $plan;
        }

        return $this->makeCatalogPlan(
            $chat,
            $trigger,
            $plan,
            'Диалог восстановлен: вместо пустого ответа или стопа отправлен каталог.',
        );
    }

    private function makeCatalogPlan(
        Chat $chat,
        Message $trigger,
        AiFunnelOrchestratorPlan $plan,
        string $reason,
    ): AiFunnelOrchestratorPlan {
        $companyId = (int) $chat->company_id;
        $reply = $this->buildCatalogReply($companyId);

        if ($this->clientUsedGreeting((string) $trigger->body) && ! $this->clientUsedGreeting($reply)) {
            $reply = 'Здравствуйте! '.$reply;
        }

        return new AiFunnelOrchestratorPlan(
            customerReply: $reply,
            targetFunnelStageId: $plan->targetFunnelStageId,
            appointment: null,
            assigneeUserId: null,
            managerNote: null,
            task: null,
            requiresManagerAttention: false,
            confidence: max($plan->confidence, 0.92),
            reason: $reason,
        );
    }

    private function normalizeClientGreeting(Chat $chat, Message $trigger, AiFunnelOrchestratorPlan $plan): AiFunnelOrchestratorPlan
    {
        if ($plan->customerReply === null || trim($plan->customerReply) === '') {
            return $plan;
        }

        if (! $this->clientUsedGreeting((string) $trigger->body)) {
            return $plan;
        }

        $hasPriorOutbound = $chat->messages()
            ->where('direction', 'outbound')
            ->where('id', '<', $trigger->id)
            ->exists();

        if ($hasPriorOutbound) {
            return $plan;
        }

        $reply = trim($plan->customerReply);
        if ($this->clientUsedGreeting($reply)) {
            return $plan;
        }

        return new AiFunnelOrchestratorPlan(
            customerReply: 'Здравствуйте! '.$reply,
            targetFunnelStageId: $plan->targetFunnelStageId,
            appointment: $plan->appointment,
            assigneeUserId: $plan->assigneeUserId,
            managerNote: $plan->managerNote,
            task: $plan->task,
            requiresManagerAttention: $plan->requiresManagerAttention,
            confidence: $plan->confidence,
            reason: $plan->reason,
        );
    }

    private function normalizeRepeatedQuestion(Chat $chat, Message $trigger, AiFunnelOrchestratorPlan $plan): AiFunnelOrchestratorPlan
    {
        if ($this->isCatalogInquiry((string) $trigger->body)) {
            return $this->normalizeCatalogInquiry($chat, $trigger, $plan);
        }

        if ($plan->customerReply === null || ! $this->looksLikeQuestion($plan->customerReply)) {
            return $plan;
        }

        $currentQuestion = $this->questionSignature($plan->customerReply);
        if ($currentQuestion === '') {
            return $plan;
        }

        $recentAiQuestions = $chat->messages()
            ->where('direction', 'outbound')
            ->whereNotNull('body')
            ->latest('id')
            ->limit(12)
            ->get(['body', 'metadata'])
            ->filter(function (Message $message): bool {
                $metadata = is_array($message->metadata) ? $message->metadata : [];

                return (bool) data_get($metadata, 'ai.generated', false)
                    && $this->looksLikeQuestion((string) $message->body);
            })
            ->map(fn (Message $message): string => $this->questionSignature((string) $message->body))
            ->filter()
            ->values();

        $isRepeated = $recentAiQuestions->contains(
            fn (string $previousQuestion): bool => $this->questionSimilarity($currentQuestion, $previousQuestion) >= 0.82
                || $this->sameMissingDataQuestion($currentQuestion, $previousQuestion),
        );

        if (! $isRepeated) {
            return $plan;
        }

        $companyId = (int) ($chat->company_id ?? 0);
        if ($companyId > 0 && $this->hasCatalogProducts($companyId)
            && ($this->shouldOfferCatalog($chat, $trigger) || $this->isClarifyingProductQuestion($currentQuestion))) {
            return $this->makeCatalogPlan(
                $chat,
                $trigger,
                $plan,
                'Вместо повторного уточняющего вопроса клиенту отправлен каталог.',
            );
        }

        return new AiFunnelOrchestratorPlan(
            customerReply: null,
            targetFunnelStageId: $plan->targetFunnelStageId,
            appointment: null,
            assigneeUserId: null,
            managerNote: 'AI остановлен антизацикливателем: он снова собирался задать клиенту похожий вопрос. Проверьте диалог и ответьте вручную.',
            task: [
                'title' => 'Проверить повторяющийся вопрос AI',
                'body' => 'AI уже задавал похожий вопрос клиенту и был остановлен, чтобы не раздражать клиента. Проверьте историю и ответьте вручную.',
            ],
            requiresManagerAttention: true,
            confidence: min($plan->confidence, 0.6),
            reason: 'AI остановлен: обнаружен повтор похожего уточняющего вопроса.',
        );
    }

    private function normalizeCompletionSignal(Chat $chat, Message $trigger, AiFunnelOrchestratorPlan $plan): AiFunnelOrchestratorPlan
    {
        $stageName = trim((string) $chat->funnelStage?->name);
        if (! in_array($stageName, ['Доставка/монтаж назначены', 'Готово к доставке/монтажу', 'Заказ выполнен'], true)) {
            return $plan;
        }

        $body = mb_strtolower((string) $trigger->body);
        $isPositiveCompletion = (str_contains($body, 'спасибо') || str_contains($body, 'благодар'))
            && (
                str_contains($body, 'выполн')
                || str_contains($body, 'доволь')
                || str_contains($body, 'отличн')
                || str_contains($body, 'все хорошо')
            );

        if (! $isPositiveCompletion) {
            return $plan;
        }

        return new AiFunnelOrchestratorPlan(
            customerReply: 'Спасибо за обратную связь! Рады, что вам всё понравилось. Будем благодарны за отзыв и всегда готовы помочь с новыми заказами.',
            targetFunnelStageId: $this->stageIdByAnyName($chat, ['Закрыто успешно', 'Заказ выполнен']),
            appointment: null,
            assigneeUserId: null,
            managerNote: null,
            task: null,
            requiresManagerAttention: false,
            confidence: 1.0,
            reason: 'Клиент подтвердил успешное выполнение заказа.',
        );
    }

    private function normalizeDeliveryScheduling(Chat $chat, Message $trigger, AiFunnelOrchestratorPlan $plan): AiFunnelOrchestratorPlan
    {
        if (trim((string) $chat->funnelStage?->name) !== 'Готово к доставке/монтажу') {
            return $plan;
        }

        $body = mb_strtolower((string) $trigger->body);
        if (! preg_match('/\b([01]?\d|2[0-3])[:.][0-5]\d\b/u', $body, $timeMatch)) {
            return $plan;
        }

        $hasDay = str_contains($body, 'сегодня')
            || str_contains($body, 'завтра')
            || str_contains($body, 'понедельник')
            || str_contains($body, 'вторник')
            || str_contains($body, 'сред')
            || str_contains($body, 'четвер')
            || str_contains($body, 'пятниц')
            || str_contains($body, 'суббот')
            || str_contains($body, 'воскрес');

        if (! $hasDay) {
            return $plan;
        }

        $time = $timeMatch[0];
        $day = str_contains($body, 'сегодня') ? 'сегодня' : (str_contains($body, 'завтра') ? 'завтра' : 'в выбранный день');
        $restrictions = str_contains($body, 'нет огранич') || str_contains($body, 'без огранич')
            ? ' Ограничения по доступу зафиксировали: нет.'
            : '';

        return new AiFunnelOrchestratorPlan(
            customerReply: "Отлично, запланировали доставку и монтаж {$day} в {$time}.{$restrictions}",
            targetFunnelStageId: $this->stageIdByName($chat, 'Доставка/монтаж назначены'),
            appointment: null,
            assigneeUserId: null,
            managerNote: null,
            task: [
                'title' => 'Доставка/монтаж назначены',
                'body' => "Клиент согласовал доставку/монтаж {$day} в {$time}.{$restrictions}",
            ],
            requiresManagerAttention: false,
            confidence: 1.0,
            reason: 'Клиент согласовал дату и время доставки/монтажа.',
        );
    }

    private function normalizeEchoReply(Chat $chat, Message $trigger, AiFunnelOrchestratorPlan $plan): AiFunnelOrchestratorPlan
    {
        if ($plan->customerReply === null) {
            return $plan;
        }

        $reply = $this->normalizedText($plan->customerReply);
        if ($reply === '') {
            return $plan;
        }

        $recentInbound = $chat->messages()
            ->where('direction', 'inbound')
            ->whereNotNull('body')
            ->latest('id')
            ->limit(5)
            ->pluck('body')
            ->map(fn (string $body): string => $this->normalizedText($body))
            ->filter()
            ->values();

        $isEcho = $recentInbound->contains(
            fn (string $inbound): bool => $inbound === $reply
                || (mb_strlen($reply) > 8 && str_contains($inbound, $reply))
                || (mb_strlen($inbound) > 8 && str_contains($reply, $inbound)),
        );
        if (! $isEcho) {
            return $plan;
        }

        $companyId = (int) ($chat->company_id ?? 0);
        if ($companyId > 0 && $this->hasCatalogProducts($companyId) && $this->shouldOfferCatalog($chat, $trigger)) {
            return $this->makeCatalogPlan(
                $chat,
                $trigger,
                $plan,
                'AI не повторяет клиента, а отвечает каталогом.',
            );
        }

        $triggerBody = trim((string) $trigger->body);
        if ($this->clientUsedGreeting($triggerBody) && mb_strlen($triggerBody) <= 40) {
            if ($companyId > 0 && $this->hasCatalogProducts($companyId)) {
                return $this->makeCatalogPlan(
                    $chat,
                    $trigger,
                    $plan,
                    'Клиент поздоровался — AI ответил приветствием и каталогом.',
                );
            }

            return new AiFunnelOrchestratorPlan(
                customerReply: 'Здравствуйте! Подскажите, что хотите подобрать — или напишите «что есть», пришлю позиции из каталога.',
                targetFunnelStageId: $plan->targetFunnelStageId,
                appointment: $plan->appointment,
                assigneeUserId: $plan->assigneeUserId,
                managerNote: null,
                task: null,
                requiresManagerAttention: false,
                confidence: max($plan->confidence, 0.88),
                reason: 'Клиент поздоровался — AI ответил приветствием без эхо.',
            );
        }

        if ($this->asksReadiness((string) $trigger->body) || $this->asksReadiness($plan->customerReply)) {
            return new AiFunnelOrchestratorPlan(
                customerReply: 'Уточню срок готовности у менеджера и сообщу вам. Если заказ уже готов, согласуем удобную дату доставки и монтажа.',
                targetFunnelStageId: $this->stageIdByAnyName($chat, ['В производстве', 'В работе']),
                appointment: null,
                assigneeUserId: null,
                managerNote: null,
                task: [
                    'title' => 'Уточнить срок готовности заказа',
                    'body' => 'Клиент спрашивает, когда будет готов заказ. Проверьте статус производства и сообщите срок.',
                ],
                requiresManagerAttention: false,
                confidence: max($plan->confidence, 0.85),
                reason: 'AI заменил эхо-вопрос на корректный ответ о сроках.',
            );
        }

        if ($companyId > 0 && $this->hasCatalogProducts($companyId)) {
            return $this->makeCatalogPlan(
                $chat,
                $trigger,
                $plan,
                'AI заменил эхо-ответ каталогом вместо заглушки.',
            );
        }

        return new AiFunnelOrchestratorPlan(
            customerReply: 'Спасибо за сообщение! Напишите, пожалуйста, что именно вас интересует — подберу вариант.',
            targetFunnelStageId: $plan->targetFunnelStageId,
            appointment: $plan->appointment,
            assigneeUserId: $plan->assigneeUserId,
            managerNote: null,
            task: null,
            requiresManagerAttention: false,
            confidence: max($plan->confidence, 0.8),
            reason: 'AI заменил эхо-ответ клиента на уточняющий вопрос без заглушки.',
        );
    }

    private function normalizedText(string $text): string
    {
        $text = mb_strtolower($text);
        $text = preg_replace('/\*[^*]+\(.*?\)\*\s*/u', '', $text) ?? $text;
        $text = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text) ?? $text;

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    private function looksLikeQuestion(string $text): bool
    {
        $text = mb_strtolower($text);

        return str_contains($text, '?')
            || str_contains($text, 'подскаж')
            || str_contains($text, 'уточн')
            || str_contains($text, 'удобно')
            || str_contains($text, 'можете')
            || str_contains($text, 'нужно ли')
            || str_contains($text, 'какой')
            || str_contains($text, 'какая')
            || str_contains($text, 'какие')
            || str_contains($text, 'когда')
            || str_contains($text, 'где');
    }

    private function questionSignature(string $text): string
    {
        $text = $this->normalizedText($text);
        $noise = [
            'здравствуйте', 'добрый', 'день', 'вечер', 'утро', 'пожалуйста',
            'подскажите', 'уточните', 'можете', 'скажите', 'спасибо',
            'чтобы', 'смог', 'смогли', 'помочь', 'дальше',
        ];

        $words = collect(explode(' ', $text))
            ->filter(fn (string $word): bool => mb_strlen($word) > 2 && ! in_array($word, $noise, true))
            ->values();

        return $words->implode(' ');
    }

    private function questionSimilarity(string $left, string $right): float
    {
        $leftWords = collect(explode(' ', $left))->filter()->unique()->values();
        $rightWords = collect(explode(' ', $right))->filter()->unique()->values();
        if ($leftWords->isEmpty() || $rightWords->isEmpty()) {
            return 0.0;
        }

        $intersection = $leftWords->intersect($rightWords)->count();
        $union = $leftWords->merge($rightWords)->unique()->count();

        return $union > 0 ? $intersection / $union : 0.0;
    }

    private function sameMissingDataQuestion(string $left, string $right): bool
    {
        if ($this->isClarifyingProductQuestion($left) && $this->isCatalogOfferQuestion($right)) {
            return false;
        }

        if ($this->isCatalogOfferQuestion($left) && $this->isClarifyingProductQuestion($right)) {
            return false;
        }

        foreach ($this->questionTopics() as $topic) {
            $leftHasTopic = collect($topic)->contains(fn (string $needle): bool => str_contains($left, $needle));
            $rightHasTopic = collect($topic)->contains(fn (string $needle): bool => str_contains($right, $needle));
            if ($leftHasTopic && $rightHasTopic) {
                return true;
            }
        }

        return false;
    }

    private function isCatalogInquiry(string $body): bool
    {
        $body = mb_strtolower(trim($body));
        if ($body === '') {
            return false;
        }

        foreach ([
            'что есть',
            'а что есть',
            'что у вас',
            'что прода',
            'ассортимент',
            'какие товар',
            'какие издел',
            'какие услуг',
            'что можете предлож',
            'что можете сделать',
            'перечислите',
            'покажите каталог',
            'ваш каталог',
            'что в наличии',
            'товар в наличии',
            'товары в наличии',
            'какие товары',
            'в наличии есть',
            'что делаете',
        ] as $needle) {
            if (str_contains($body, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function shouldOfferCatalog(Chat $chat, Message $trigger): bool
    {
        if ($this->isCatalogInquiry((string) $trigger->body)) {
            return true;
        }

        if ($this->chatHasRecentCatalogInquiry($chat, (int) $trigger->id)) {
            return true;
        }

        if ($this->isPurchaseIntent((string) $trigger->body) && $this->aiRecentlyAskedClarifyingProductQuestion($chat)) {
            return true;
        }

        return false;
    }

    private function chatHasRecentCatalogInquiry(Chat $chat, int $beforeMessageId, int $limit = 10): bool
    {
        return Message::query()
            ->where('chat_id', $chat->id)
            ->where('direction', 'inbound')
            ->where('id', '<', $beforeMessageId)
            ->latest('id')
            ->limit($limit)
            ->pluck('body')
            ->contains(fn (mixed $body): bool => $this->isCatalogInquiry((string) $body)
                || $this->isPurchaseIntent((string) $body));
    }

    private function isPurchaseIntent(string $body): bool
    {
        $body = mb_strtolower(trim($body));

        return str_contains($body, 'купить')
            || str_contains($body, 'приобрест')
            || str_contains($body, 'заказать')
            || str_contains($body, 'хочу что')
            || str_contains($body, 'что то купить')
            || str_contains($body, 'интересует');
    }

    private function aiRecentlyAskedClarifyingProductQuestion(Chat $chat): bool
    {
        return $chat->messages()
            ->where('direction', 'outbound')
            ->whereNotNull('body')
            ->latest('id')
            ->limit(8)
            ->get(['body', 'metadata'])
            ->contains(function (Message $message): bool {
                if (! (bool) data_get($message->metadata, 'ai.generated', false)) {
                    return false;
                }

                $signature = $this->questionSignature((string) $message->body);

                return $this->isClarifyingProductQuestion($signature);
            });
    }

    private function hasCatalogProducts(int $companyId): bool
    {
        $data = $this->knowledge->forPrompt($companyId);

        return $data['products'] !== [] || $data['services'] !== [];
    }

    private function isGenericStubReply(?string $reply): bool
    {
        if ($reply === null || trim($reply) === '') {
            return true;
        }

        $text = mb_strtolower(trim($reply));

        return str_contains($text, 'уточню информацию и вернусь')
            || str_contains($text, 'понял вас. уточню')
            || str_contains($text, 'вернусь с ответом');
    }

    private function isRepeatStopReason(string $reason): bool
    {
        $reason = mb_strtolower(trim($reason));

        return str_contains($reason, 'повтор')
            || str_contains($reason, 'уточняющ')
            || str_contains($reason, 'не уверен');
    }

    private function isClarifyingProductQuestion(string $signature): bool
    {
        return str_contains($signature, 'именно')
            || str_contains($signature, 'уточн')
            || str_contains($signature, 'какое издел')
            || str_contains($signature, 'какую категор')
            || str_contains($signature, 'хотите купить');
    }

    private function isCatalogOfferQuestion(string $signature): bool
    {
        return str_contains($signature, 'есть')
            || str_contains($signature, 'прода')
            || str_contains($signature, 'ассортимент')
            || str_contains($signature, 'вариант')
            || str_contains($signature, 'каталог')
            || str_contains($signature, 'наличии');
    }

    private function clientUsedGreeting(string $body): bool
    {
        $body = mb_strtolower($body);

        return str_contains($body, 'здравств')
            || str_contains($body, 'добрый')
            || str_contains($body, 'доброе')
            || str_contains($body, 'привет');
    }

    private function buildCatalogReply(int $companyId): string
    {
        $data = $this->knowledge->forPrompt($companyId);
        $products = collect($data['products']);
        $services = collect($data['services']);

        if ($products->isEmpty() && $services->isEmpty()) {
            return 'Сейчас в каталоге нет готового списка в системе — менеджер уточнит ассортимент и пришлёт варианты. Напишите, что вас интересует.';
        }

        $parts = [];

        if ($products->isNotEmpty()) {
            $lines = $products
                ->take(8)
                ->map(function (Product $product): string {
                    $line = '• '.$product->name;
                    if ($product->price !== null) {
                        $line .= ' — '.number_format((float) $product->price, 0, '.', ' ').' ₸';
                    }

                    return $line;
                })
                ->implode("\n");
            $parts[] = "У нас в каталоге:\n{$lines}";
            if ($products->count() > 8) {
                $parts[] = 'Есть и другие позиции — напишите, что интересует, пришлём подробности.';
            }
        }

        if ($services->isNotEmpty()) {
            $lines = $services
                ->take(5)
                ->map(function (Service $service): string {
                    $line = '• '.$service->name;
                    if ($service->price !== null) {
                        $line .= ' — '.number_format((float) $service->price, 0, '.', ' ').' ₸';
                    }

                    return $line;
                })
                ->implode("\n");
            $parts[] = "Услуги:\n{$lines}";
        }

        return implode("\n\n", $parts)."\n\nЧто из этого вас интересует?";
    }

    /**
     * @return list<list<string>>
     */
    private function questionTopics(): array
    {
        return [
            ['адрес', 'район', 'город', 'улиц'],
            ['время', 'дата', 'когда', 'удобно', 'завтра', 'сегодня'],
            ['размер', 'габарит', 'длина', 'ширина', 'высота', 'метр'],
            ['именно хотите', 'какое издел', 'какую категор', 'уточните издел'],
            ['бюджет', 'стоим', 'цен'],
            ['оплат', 'предоплат', 'реквизит'],
            ['достав', 'монтаж', 'лифт', 'парков', 'огранич'],
        ];
    }

    private function asksReadiness(string $text): bool
    {
        $text = mb_strtolower($text);

        return (str_contains($text, 'когда') && (str_contains($text, 'готов') || str_contains($text, 'будет')))
            || str_contains($text, 'срок готов')
            || str_contains($text, 'срок производ');
    }

    private function normalizePaymentStage(Chat $chat, Message $trigger, AiFunnelOrchestratorPlan $plan): AiFunnelOrchestratorPlan
    {
        if (trim((string) $chat->funnelStage?->name) !== 'Ожидание предоплаты') {
            return $plan;
        }

        $body = mb_strtolower((string) $trigger->body);
        if ($this->mentionsPaymentDone($body)) {
            return new AiFunnelOrchestratorPlan(
                customerReply: 'Спасибо, зафиксировали. Передаём оплату на проверку и после подтверждения запускаем заказ в работу.',
                targetFunnelStageId: $this->stageIdByName($chat, 'Предоплата получена'),
                appointment: null,
                assigneeUserId: null,
                managerNote: null,
                task: null,
                requiresManagerAttention: false,
                confidence: 1.0,
                reason: 'Клиент сообщил, что предоплата внесена.',
            );
        }

        if (str_contains($body, 'позже') || str_contains($body, 'потом') || str_contains($body, 'реквизит')) {
            return new AiFunnelOrchestratorPlan(
                customerReply: 'Хорошо, зафиксировали. Реквизиты передаст менеджер, оплату можно внести позже.',
                targetFunnelStageId: null,
                appointment: null,
                assigneeUserId: null,
                managerNote: null,
                task: [
                    'title' => 'Отправить реквизиты клиенту',
                    'body' => 'Клиент попросил реквизиты и сообщил, что оплату внесёт позже.',
                ],
                requiresManagerAttention: false,
                confidence: 1.0,
                reason: 'Клиент попросил реквизиты или отложил оплату.',
            );
        }

        return $plan;
    }

    private function mentionsPaymentDone(string $body): bool
    {
        return str_contains($body, 'уже опла')
            || str_contains($body, 'оплачено')
            || str_contains($body, 'оплатил')
            || str_contains($body, 'оплатила')
            || str_contains($body, 'предоплат')
            || str_contains($body, 'внес')
            || str_contains($body, 'внёс');
    }

    private function normalizeMeasurementHandoff(Chat $chat, AiFunnelOrchestratorPlan $plan): AiFunnelOrchestratorPlan
    {
        if ($plan->appointment === null || $plan->assigneeUserId === null || $chat->funnel === null) {
            return $plan;
        }

        $handoffStage = $chat->funnel->stages->first(
            fn ($stage): bool => trim((string) $stage->name) === 'Передано замерщику',
        );

        if ($handoffStage === null) {
            return $plan;
        }

        return new AiFunnelOrchestratorPlan(
            customerReply: $plan->customerReply,
            targetFunnelStageId: (int) $handoffStage->id,
            appointment: $plan->appointment,
            assigneeUserId: $plan->assigneeUserId,
            managerNote: $plan->managerNote,
            task: $plan->task,
            requiresManagerAttention: $plan->requiresManagerAttention,
            confidence: $plan->confidence,
            reason: $plan->reason,
        );
    }

    private function productInterestReply(Message $trigger): string
    {
        $product = $this->productLabel((string) $trigger->body);

        return "Понял, интересует {$product}. Подскажите, пожалуйста, примерные размеры, адрес/район и когда удобно записаться на замер?";
    }

    private function questionFallbackPlan(
        Chat $chat,
        Message $trigger,
        ?FunnelStageAiRule $rule,
        AiFunnelOrchestratorPlan $plan,
        float $minConfidence,
    ): ?AiFunnelOrchestratorPlan {
        $allowed = $rule?->allowed_actions;
        if (is_array($allowed) && ! in_array(FunnelStageAiRule::ACTION_REPLY_CUSTOMER, $allowed, true)) {
            return null;
        }

        $targetStageId = null;
        $questions = $rule?->required_questions;
        if ($this->isFirstStageProductInterest($chat, $trigger)) {
            $targetStageId = $this->nextStageId($chat);
            $nextRule = $targetStageId !== null
                ? FunnelStageAiRule::query()->where('funnel_stage_id', $targetStageId)->first()
                : null;
            $questions = $nextRule?->required_questions ?: $questions;
        }

        if (! is_array($questions) || $questions === []) {
            return null;
        }

        return new AiFunnelOrchestratorPlan(
            customerReply: $this->questionFallbackReply($questions, $trigger),
            targetFunnelStageId: $targetStageId,
            appointment: null,
            assigneeUserId: null,
            managerNote: null,
            task: null,
            requiresManagerAttention: false,
            confidence: max($plan->confidence, $minConfidence),
            reason: 'AI задал уточняющий вопрос по правилам этапа.',
        );
    }

    private function isFirstStageProductInterest(Chat $chat, Message $trigger): bool
    {
        if ($chat->funnelStage?->position !== 0) {
            return false;
        }

        $body = mb_strtolower((string) $trigger->body);
        foreach (['кухн', 'шкаф', 'гардероб', 'прихож', 'тумб', 'комод', 'мебел'] as $needle) {
            if (str_contains($body, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function productLabel(string $body): string
    {
        $body = mb_strtolower($body);

        return match (true) {
            str_contains($body, 'кухн') => 'кухня',
            str_contains($body, 'шкаф') => 'шкаф',
            str_contains($body, 'гардероб') => 'гардеробная',
            str_contains($body, 'прихож') => 'прихожая',
            str_contains($body, 'тумб') => 'тумба',
            str_contains($body, 'комод') => 'комод',
            default => 'мебель',
        };
    }

    private function nextStageId(Chat $chat): ?int
    {
        $currentPosition = $chat->funnelStage?->position;
        if ($currentPosition === null || $chat->funnel === null) {
            return null;
        }

        $next = $chat->funnel->stages
            ->where('position', '>', $currentPosition)
            ->sortBy('position')
            ->first();

        return $next?->id ? (int) $next->id : null;
    }

    private function stageIdByName(Chat $chat, string $name): ?int
    {
        $stage = $chat->funnel?->stages->first(
            fn ($stage): bool => trim((string) $stage->name) === $name,
        );

        return $stage?->id ? (int) $stage->id : null;
    }

    /**
     * @param  list<string>  $names
     */
    private function stageIdByAnyName(Chat $chat, array $names): ?int
    {
        foreach ($names as $name) {
            $stageId = $this->stageIdByName($chat, $name);
            if ($stageId !== null) {
                return $stageId;
            }
        }

        return null;
    }

    /**
     * @param  array<int, mixed>  $questions
     */
    private function questionFallbackReply(array $questions, Message $trigger): string
    {
        $body = mb_strtolower((string) $trigger->body);
        $clean = collect($questions)
            ->filter(fn (mixed $question): bool => is_string($question) && trim($question) !== '')
            ->map(fn (string $question): string => rtrim(trim($question), " ?\t\n\r\0\x0B"))
            ->reject(fn (string $question): bool => $this->questionLooksAnswered($question, $body))
            ->take(1)
            ->values();

        if ($clean->isEmpty()) {
            return 'Спасибо, данные принял. Уточню следующий шаг и вернусь с ответом.';
        }

        return 'Здравствуйте! Подскажите, пожалуйста: '.$clean->implode('; ').'.';
    }

    private function questionLooksAnswered(string $question, string $body): bool
    {
        $question = mb_strtolower($question);
        $hasNumber = preg_match('/\d/u', $body) === 1;
        $hasTime = preg_match('/\b([01]?\d|2[0-3])[:.][0-5]\d\b/u', $body) === 1
            || str_contains($body, 'сегодня')
            || str_contains($body, 'завтра')
            || str_contains($body, 'понедельник')
            || str_contains($body, 'вторник')
            || str_contains($body, 'сред')
            || str_contains($body, 'четвер')
            || str_contains($body, 'пятниц')
            || str_contains($body, 'суббот')
            || str_contains($body, 'воскрес');

        return match (true) {
            str_contains($question, 'адрес') || str_contains($question, 'город') || str_contains($question, 'район') => str_contains($body, 'адрес') || str_contains($body, 'ул') || str_contains($body, 'район') || str_contains($body, 'город') || $hasNumber,
            str_contains($question, 'время') || str_contains($question, 'дат') || str_contains($question, 'срок') => $hasTime,
            str_contains($question, 'бюджет') || str_contains($question, 'цен') || str_contains($question, 'стоим') => str_contains($body, 'тг') || str_contains($body, 'тенге') || str_contains($body, '₸') || $hasNumber,
            str_contains($question, 'оплат') || str_contains($question, 'предоплат') || str_contains($question, 'реквизит') => $this->mentionsPaymentDone($body) || str_contains($body, 'позже') || str_contains($body, 'потом') || str_contains($body, 'реквизит'),
            str_contains($question, 'огранич') || str_contains($question, 'лифт') || str_contains($question, 'парков') => str_contains($body, 'огранич') || str_contains($body, 'лифт') || str_contains($body, 'парков') || str_contains($body, 'нет'),
            default => false,
        };
    }

    private function actor(Chat $chat, FunnelAiScenario $scenario): ?User
    {
        if ($scenario->fallbackManager instanceof User && $scenario->fallbackManager->is_active) {
            return $scenario->fallbackManager;
        }
        if ($chat->aiResponder instanceof User && $chat->aiResponder->is_active) {
            return $chat->aiResponder;
        }

        return $chat->assignments->first(fn ($assignment) => $assignment->user?->is_active)?->user;
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    private function candidateAssignees(Chat $chat, FunnelAiScenario $scenario, ?FunnelStageAiRule $rule): array
    {
        $ids = collect($rule?->assignee_user_ids ?? [])
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->values();

        if ($ids->isEmpty() && $rule?->assigneeDepartment !== null) {
            $ids = $rule->assigneeDepartment->users()->where('is_active', true)->pluck('users.id')->map(fn ($id) => (int) $id);
        }
        if ($ids->isEmpty() && $scenario->fallbackDepartment !== null) {
            $ids = $scenario->fallbackDepartment->users()->where('is_active', true)->pluck('users.id')->map(fn ($id) => (int) $id);
        }
        if ($ids->isEmpty()) {
            $ids = $chat->assignments->pluck('user_id')->map(fn ($id) => (int) $id);
        }

        return User::query()
            ->whereIn('id', $ids->unique()->values()->all())
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (User $user): array => ['id' => $user->id, 'name' => $user->name])
            ->values()
            ->all();
    }

    /**
     * @param  list<array{id: int, name: string}>  $candidateAssignees
     * @return list<array{user_id: int, user_name: string, starts_at: string, ends_at: string}>
     */
    private function availableSlots(array $candidateAssignees, int $horizonDays): array
    {
        $users = User::query()->whereIn('id', collect($candidateAssignees)->pluck('id')->all())->get()->keyBy('id');
        $slots = [];
        $timezone = (string) config('app.timezone', 'UTC');
        $now = Carbon::now($timezone);
        $startDay = $now->copy()->startOfDay();
        $horizonDays = max(1, min(60, $horizonDays));

        for ($day = 0; $day < $horizonDays && count($slots) < 30; $day++) {
            $date = $startDay->copy()->addDays($day);
            for ($hour = 9; $hour <= 18 && count($slots) < 30; $hour++) {
                $startsAt = $date->copy()->setTime($hour, 0);
                $endsAt = $startsAt->copy()->addHour();
                if ($startsAt->lessThanOrEqualTo($now)) {
                    continue;
                }
                foreach ($candidateAssignees as $candidate) {
                    $user = $users->get($candidate['id']);
                    if (! $user instanceof User) {
                        continue;
                    }
                    if ($this->availability->hasConflict($user, $startsAt, $endsAt)) {
                        continue;
                    }
                    $slots[] = [
                        'user_id' => $user->id,
                        'user_name' => $user->name,
                        'starts_at' => $startsAt->toIso8601String(),
                        'ends_at' => $endsAt->toIso8601String(),
                    ];
                    if (count($slots) >= 30) {
                        break 3;
                    }
                }
            }
        }

        return $slots;
    }

    private function finishSkipped(AiOrchestratorRun $run, Chat $chat, string $reason): void
    {
        $run->forceFill([
            'status' => AiOrchestratorRun::STATUS_SKIPPED,
            'reason' => $reason,
            'completed_at' => now(),
        ])->save();
        $chat->forceFill([
            'ai_orchestrator_status' => AiOrchestratorRun::STATUS_SKIPPED,
            'ai_orchestrator_last_run_id' => $run->id,
            'ai_orchestrator_last_action_at' => now(),
            'ai_orchestrator_last_summary' => $reason,
        ])->save();
        $this->broadcastStatus($chat);
    }

    private function broadcastStatus(Chat $chat): void
    {
        broadcast(new ChatAiOrchestratorUpdated($chat->id, [
            'ai_orchestrator_status' => $chat->ai_orchestrator_status,
            'ai_orchestrator_last_run_id' => $chat->ai_orchestrator_last_run_id,
            'ai_orchestrator_last_action_at' => $chat->ai_orchestrator_last_action_at?->toIso8601String(),
            'ai_orchestrator_last_summary' => $chat->ai_orchestrator_last_summary,
        ]));
    }
}
