<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\CalendarEvent;
use App\Models\Chat;
use App\Models\FunnelAiScenario;
use App\Models\FunnelStageAiRule;
use App\Models\Message;
use App\Models\User;
use App\Services\AI\Locale\LocalePromptAugmenter;
use App\Support\AiFeatureFlags;
use App\Support\MessageInboundText;

final class AiFunnelPlannerService
{
    public function __construct(
        private readonly OpenAiChatService $openAi,
        private readonly PromptBuilder $promptBuilder,
        private readonly LocalePromptAugmenter $localeAugmenter,
        private readonly ChatSalesStateService $salesStateService,
    ) {}

    /**
     * @param  list<array{id: int, name: string}>  $candidateAssignees
     * @param  list<array{user_id: int, user_name: string, starts_at: string, ends_at: string}>  $availableSlots
     * @return array{0: AiFunnelOrchestratorPlan, 1: array<string, mixed>}
     */
    public function plan(
        Chat $chat,
        Message $trigger,
        User $responder,
        FunnelAiScenario $scenario,
        ?FunnelStageAiRule $rule,
        array $candidateAssignees,
        array $availableSlots,
    ): array {
        $base = $this->promptBuilder->build(
            $chat,
            $responder,
            trim(MessageInboundText::forMessage($trigger)),
            $chat->company_id ?? $responder->company_id,
            $trigger,
        );

        $context = $this->contextPayload($chat, $trigger, $scenario, $rule, $candidateAssignees, $availableSlots);
        $triggerText = trim(MessageInboundText::forMessage($trigger));
        $locale = $this->localeAugmenter->augment($triggerText, $chat, $chat->company_id ?? $responder->company_id, $trigger);
        $messages = [
            ...$base['messages'],
            ['role' => 'system', 'content' => $this->localeAugmenter->workspaceLanguageInstruction($locale['profile'])],
            ['role' => 'system', 'content' => $this->orchestratorPrompt($context)],
            ['role' => 'user', 'content' => 'Сформируй JSON-план следующего шага AI-оркестратора по последнему сообщению клиента.'],
        ];

        $raw = $this->openAi->chatJson(
            $messages,
            (float) config('funnel.orchestrator.temperature', 0.2),
            (int) config('funnel.orchestrator.max_tokens', 1200),
            new AiUsageOptions('funnel_orchestrator', $chat->company_id ?? $responder->company_id),
        );

        return [AiFunnelOrchestratorPlan::fromArray($raw), $context];
    }

    /**
     * @param  list<array{id: int, name: string}>  $candidateAssignees
     * @param  list<array{user_id: int, user_name: string, starts_at: string, ends_at: string}>  $availableSlots
     * @return array<string, mixed>
     */
    private function contextPayload(
        Chat $chat,
        Message $trigger,
        FunnelAiScenario $scenario,
        ?FunnelStageAiRule $rule,
        array $candidateAssignees,
        array $availableSlots,
    ): array {
        $chat->loadMissing(['funnel.stages', 'funnelStage']);
        $triggerText = trim(MessageInboundText::forMessage($trigger));
        $locale = $this->localeAugmenter->augment($triggerText, $chat, $chat->company_id, $trigger);

        return [
            'chat_id' => $chat->id,
            'trigger_message_id' => $trigger->id,
            'trigger_message' => $triggerText,
            'client_locale' => $locale['profile']->toArray(),
            'current_funnel' => $chat->funnel ? [
                'id' => $chat->funnel->id,
                'name' => $chat->funnel->name,
                'stages' => $chat->funnel->stages->map(fn ($stage) => [
                    'id' => $stage->id,
                    'name' => $stage->name,
                    'position' => $stage->position,
                ])->values()->all(),
            ] : null,
            'current_stage' => $chat->funnelStage ? [
                'id' => $chat->funnelStage->id,
                'name' => $chat->funnelStage->name,
                'position' => $chat->funnelStage->position,
            ] : null,
            'scenario' => [
                'booking_horizon_days' => $scenario->booking_horizon_days,
                'manager_confirmation_required' => $scenario->manager_confirmation_required,
                'customer_identity' => $scenario->customer_identity,
            ],
            'stage_rule' => $rule ? [
                'goal' => $rule->goal,
                'required_questions' => $rule->required_questions ?? [],
                'transition_conditions' => $rule->transition_conditions,
                'allowed_actions' => $rule->allowed_actions ?: FunnelStageAiRule::DEFAULT_ALLOWED_ACTIONS,
                'require_manager_confirmation' => $rule->require_manager_confirmation,
            ] : null,
            'candidate_assignees' => $candidateAssignees,
            'available_slots' => $availableSlots,
            'sales_state' => AiFeatureFlags::enabled(AiFeatureFlags::SALES_STATE, $chat->company_id)
                ? ($chat->sales_state ?? null)
                : null,
            'existing_appointments' => CalendarEvent::query()
                ->where('chat_id', $chat->id)
                ->where('starts_at', '>=', now()->subDays(ChatCalendarContextBuilder::PAST_DAYS)->startOfDay())
                ->where('starts_at', '<=', now()->addDays(ChatCalendarContextBuilder::FUTURE_DAYS)->endOfDay())
                ->orderBy('starts_at')
                ->limit(8)
                ->get(['id', 'title', 'starts_at', 'ends_at', 'assignee_user_id', 'source'])
                ->map(fn (CalendarEvent $event): array => [
                    'id' => $event->id,
                    'title' => $event->title,
                    'starts_at' => $event->starts_at?->toIso8601String(),
                    'ends_at' => $event->ends_at?->toIso8601String(),
                    'assignee_user_id' => $event->assignee_user_id,
                    'source' => $event->source,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function orchestratorPrompt(array $context): string
    {
        $json = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return <<<PROMPT
Ты — AI-оркестратор воронки продаж Accel. Ты пишешь клиенту от лица компании, не раскрывая AI.

Верни только JSON без Markdown:
{
  "customer_reply": string|null,
  "target_funnel_stage_id": number|null,
  "appointment_request": {
    "service_name": string,
    "starts_at": string,
    "duration_minutes": number,
    "client_note": string|null,
    "reminder_lead_minutes": number|null
  }|null,
  "assignee_user_id": number|null,
  "manager_note": string|null,
  "task": {
    "title": string,
    "body": string
  }|null,
  "requires_manager_attention": boolean,
  "confidence": number,
  "reason": string
}

Правила:
1. Выполняй только действия, которые есть в stage_rule.allowed_actions.
2. Если данных для записи не хватает — customer_reply должен задать короткий уточняющий вопрос, appointment_request должен быть null.
3. Если предлагаешь запись, выбирай только время из available_slots и assignee_user_id того же слота.
4. target_funnel_stage_id выбирай только из current_funnel.stages и только когда условия этапа выполнены.
5. Если нужен менеджер, поставь requires_manager_attention=true и дай manager_note/task.
6. Не обещай клиенту, что запись создана или сотрудник назначен, если просишь менеджера подтвердить.
7. Ответ клиенту должен быть коротким, естественным и без служебных деталей.
8. Не повторяй сообщение клиента как customer_reply. Ответ должен быть репликой компании.
9. Если appointment_request не null, время в customer_reply обязано совпадать со starts_at appointment_request в часовом поясе клиента/компании.
10. Если в existing_appointments уже есть запись и клиент просит перенести/перезаписать на другое время — это перенос: создай appointment_request на новый слот из available_slots, requires_manager_attention=false, не проси менеджера. Обнови существующую запись, а не создавай дубль.
11. Если нужно просто уточнить параметры, адрес, изделие или время — задай вопрос клиенту. Не ставь requires_manager_attention=true только из-за нехватки обычных данных.
12. Перед любым вопросом проверь историю и последнее сообщение клиента. Не спрашивай повторно данные, которые клиент уже дал: адрес, изделие, размер, дату, время, оплату, ограничения доставки или подтверждение выполнения.
13. Если клиент сообщает, что оплатил/внёс предоплату, переводи в следующий подходящий этап и не проси реквизиты или дату оплаты повторно.
14. Если клиент благодарит и пишет, что заказ выполнен/всё понравилось, ответь благодарностью и переведи в успешное закрытие, если такой этап есть.
15. Если точный срок, скидку или нестандартное условие нельзя подтвердить из контекста — не выдумывай. Создай task/manager_note и дай клиенту короткий ответ, что уточним.
16. Если клиент спрашивает, что есть / ассортимент / какие товары или услуги — перечисли кратко позиции из базы знаний в customer_reply. Не задавай снова вопрос «что именно хотите купить».
17. Если клиент поздоровался и это первый ответ компании в чате — начни с короткого приветствия (Здравствуйте / Добрый день), без шаблонов вроде «Спасибо за интерес».
18. Если клиент просит напомнить/предупредить за N часов или минут до визита — укажи reminder_lead_minutes в минутах (30 = полчаса, 120 = 2 часа) и подтверди это в customer_reply.
19. Первичная новая запись (existing_appointments пуст) при manager_confirmation_required в сценарии — можно requires_manager_attention=true; перенос существующей записи — всегда без менеджера, если слот выбран.
20. Если клиент просил конкретное время, а в appointment_request выбран другой слот из available_slots — в customer_reply обязательно объясни: запрошенное время занято, предлагаешь ближайшее свободное (назови оба времени). Не пиши так, будто записал на запрошенное время.
21. Язык customer_reply всегда совпадает с языком последнего сообщения клиента (trigger_message / client_locale). Если клиент переключился с русского на казахский или наоборот — отвечай на новом языке.
22. Клиент может сменить тему в любой момент. Отвечай на последнее сообщение, а не продолжай предыдущую тему, если клиент уже задал новый вопрос (срок, цена, доставка, оплата, адрес).
23. Не отправляй каталог, если клиент спрашивает о сроках, цене, доставке, оплате или адресе — ответь по этой теме.
24. Если клиент спрашивает о своей записи, напоминании или времени визита — смотри existing_appointments. Называй точную дату и время из ISO-полей starts_at/ends_at, не «завтра»/«сегодня» из старых реплик, если календарная дата уже в прошлом или в другой день.
25. Дата и время записи могут быть в разных сообщениях: если клиент сначала согласовал визит/покупку на сегодня, а следующим сообщением уточнил время («в 18», «18:00-ге») — собери appointment_request из всей истории и создай запись, не спрашивай повторно то, что уже сказано.
26. Если в customer_reply подтверждаешь запись на конкретное время — appointment_request обязан быть заполнен и совпадать с этим временем.
27. Не требуй слово «запись»: подъехать, забрать, купить сегодня, прийти, когда удобно, успею сегодня — это тоже согласование визита, если есть дата и время.
28. Если в истории компания спросила про удобное время, а клиент ответил временем или коротким «да/жарайды» — используй контекст переписки и создай appointment_request.
29. Если клиент недоволен, жалуется, угрожает, требует возврат или использует грубость — сначала признай эмоцию коротко, без спора и без обещания компенсации без правил из базы знаний.
30. При серьёзном конфликте (возврат, обман, агрессия, юридическая угроза) после 1–2 успокаивающих ответов ставь requires_manager_attention=true, создай task и не продолжай давить на клиента.
31. Не зеркаль мат и CAPS. Не обещай возврат денег или юридическое решение — только процесс и передачу менеджеру.

Контекст оркестратора:
{$json}
PROMPT;
    }
}
