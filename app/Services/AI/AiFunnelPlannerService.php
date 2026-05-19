<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\Chat;
use App\Models\FunnelAiScenario;
use App\Models\FunnelStageAiRule;
use App\Models\Message;
use App\Models\User;

final class AiFunnelPlannerService
{
    public function __construct(
        private readonly OpenAiChatService $openAi,
        private readonly PromptBuilder $promptBuilder,
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
            trim((string) $trigger->body),
            $chat->company_id ?? $responder->company_id,
        );

        $context = $this->contextPayload($chat, $trigger, $scenario, $rule, $candidateAssignees, $availableSlots);
        $messages = [
            ...$base['messages'],
            ['role' => 'system', 'content' => $this->orchestratorPrompt($context)],
            ['role' => 'user', 'content' => 'Сформируй JSON-план следующего шага AI-оркестратора по последнему сообщению клиента.'],
        ];

        $raw = $this->openAi->chatJson(
            $messages,
            (float) config('funnel.orchestrator.temperature', 0.2),
            (int) config('funnel.orchestrator.max_tokens', 1200),
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

        return [
            'chat_id' => $chat->id,
            'trigger_message_id' => $trigger->id,
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
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function orchestratorPrompt(array $context): string
    {
        $json = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return <<<PROMPT
Ты — AI-оркестратор воронки продаж ChatSwitch. Ты пишешь клиенту от лица компании, не раскрывая AI.

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
10. Если клиент просит перенести уже назначенный замер и новый слот есть в available_slots, создай appointment_request на новый слот и не проси менеджера.
11. Если нужно просто уточнить параметры, адрес, изделие или время — задай вопрос клиенту. Не ставь requires_manager_attention=true только из-за нехватки обычных данных.
12. Перед любым вопросом проверь историю и последнее сообщение клиента. Не спрашивай повторно данные, которые клиент уже дал: адрес, изделие, размер, дату, время, оплату, ограничения доставки или подтверждение выполнения.
13. Если клиент сообщает, что оплатил/внёс предоплату, переводи в следующий подходящий этап и не проси реквизиты или дату оплаты повторно.
14. Если клиент благодарит и пишет, что заказ выполнен/всё понравилось, ответь благодарностью и переведи в успешное закрытие, если такой этап есть.
15. Если точный срок, скидку или нестандартное условие нельзя подтвердить из контекста — не выдумывай. Создай task/manager_note и дай клиенту короткий ответ, что уточним.
16. Если клиент спрашивает, что есть / ассортимент / какие товары или услуги — перечисли кратко позиции из базы знаний в customer_reply. Не задавай снова вопрос «что именно хотите купить».
17. Если клиент поздоровался и это первый ответ компании в чате — начни с короткого приветствия (Здравствуйте / Добрый день), без шаблонов вроде «Спасибо за интерес».
18. Если клиент просит напомнить/предупредить за N часов или минут до визита — укажи reminder_lead_minutes в минутах (30 = полчаса, 120 = 2 часа) и подтверди это в customer_reply.

Контекст оркестратора:
{$json}
PROMPT;
    }
}
