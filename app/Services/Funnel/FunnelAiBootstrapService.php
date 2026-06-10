<?php

declare(strict_types=1);

namespace App\Services\Funnel;

use App\Models\Department;
use App\Models\Funnel;
use App\Models\FunnelAiScenario;
use App\Models\FunnelStage;
use App\Models\FunnelStageAiRule;
use App\Support\FunnelStageType;

/**
 * Создаёт AI-сценарий воронки и правила этапов при создании воронки/этапа.
 */
final class FunnelAiBootstrapService
{
    /**
     * @param  list<string>  $questions
     * @param  list<string>|null  $allowedActions
     */
    public function createStageRuleFromDefinition(
        Funnel $funnel,
        FunnelStage $stage,
        string $goal,
        array $questions,
        string $conditions,
        ?int $assigneeDepartmentId = null,
        bool $managerConfirmation = false,
        ?array $allowedActions = null,
    ): FunnelStageAiRule {
        return FunnelStageAiRule::query()->updateOrCreate(
            ['funnel_stage_id' => $stage->id],
            [
                'company_id' => $funnel->company_id,
                'funnel_id' => $funnel->id,
                'goal' => $goal,
                'required_questions' => array_values($questions),
                'transition_conditions' => $conditions,
                'allowed_actions' => $allowedActions ?? FunnelStageAiRule::DEFAULT_ALLOWED_ACTIONS,
                'assignee_user_ids' => [],
                'assignee_department_id' => $assigneeDepartmentId,
                'require_manager_confirmation' => $managerConfirmation,
            ],
        );
    }

    public function ensureScenario(
        Funnel $funnel,
        ?int $fallbackDepartmentId = null,
        bool $enabled = false,
    ): FunnelAiScenario {
        $departmentId = $fallbackDepartmentId ?? $this->resolveFallbackDepartmentId((int) $funnel->company_id);

        return FunnelAiScenario::query()->updateOrCreate(
            ['funnel_id' => $funnel->id],
            [
                'company_id' => $funnel->company_id,
                'enabled' => $enabled,
                'customer_identity' => 'company',
                'booking_horizon_days' => 30,
                'fallback_manager_user_id' => null,
                'fallback_department_id' => $departmentId,
                'manager_confirmation_required' => false,
            ],
        );
    }

    public function ensureStageRule(
        Funnel $funnel,
        FunnelStage $stage,
        int $index,
        int $totalStages,
        ?int $fallbackDepartmentId = null,
    ): FunnelStageAiRule {
        $definition = $this->suggestStageRuleDefinition($stage, $index, $totalStages, $fallbackDepartmentId);

        return $this->createStageRuleFromDefinition(
            $funnel,
            $stage,
            $definition['goal'],
            $definition['questions'],
            $definition['conditions'],
            $definition['assignee_department_id'],
            $definition['manager_confirmation'],
            $definition['allowed_actions'],
        );
    }

    public function bootstrapFunnel(Funnel $funnel, bool $enableScenario = false): void
    {
        $departmentId = $this->resolveFallbackDepartmentId((int) $funnel->company_id);
        $this->ensureScenario($funnel, $departmentId, $enableScenario);

        $stages = $funnel->stages()->orderBy('position')->orderBy('id')->get();
        $total = $stages->count();

        foreach ($stages as $index => $stage) {
            if (! $stage instanceof FunnelStage) {
                continue;
            }

            $this->ensureStageRule($funnel, $stage, $index, $total, $departmentId);
        }
    }

    /**
     * @return array{
     *     goal: string,
     *     questions: list<string>,
     *     conditions: string,
     *     allowed_actions: list<string>,
     *     assignee_department_id: int|null,
     *     manager_confirmation: bool
     * }
     */
    public function suggestStageRuleDefinition(
        FunnelStage $stage,
        int $index,
        int $totalStages,
        ?int $fallbackDepartmentId = null,
    ): array {
        $name = mb_strtolower(trim($stage->name));
        $stageType = FunnelStageType::normalize($stage->stage_type);
        $isFinal = $totalStages > 0 && $index >= $totalStages - 1;

        $baseActions = [
            FunnelStageAiRule::ACTION_REPLY_CUSTOMER,
            FunnelStageAiRule::ACTION_MOVE_FUNNEL_STAGE,
            FunnelStageAiRule::ACTION_NOTIFY_MANAGER,
            FunnelStageAiRule::ACTION_CREATE_TASK,
        ];

        if ($this->nameMatches($name, ['запись', 'приём', 'прием', 'замер', 'показ', 'созвон'])) {
            return [
                'goal' => 'Согласовать с клиентом удобную дату, время и ответственного.',
                'questions' => ['Удобная дата и время', 'Адрес или формат встречи', 'Контактное лицо'],
                'conditions' => 'Перейти дальше, когда клиент подтвердил дату и время. Если данных не хватает, задать один короткий уточняющий вопрос.',
                'allowed_actions' => [
                    ...$baseActions,
                    FunnelStageAiRule::ACTION_CREATE_APPOINTMENT,
                    FunnelStageAiRule::ACTION_ASSIGN_EMPLOYEE,
                ],
                'assignee_department_id' => $fallbackDepartmentId,
                'manager_confirmation' => false,
            ];
        }

        if ($this->nameMatches($name, ['оплат', 'предоплат'])) {
            if (! (bool) config('funnel.payment_stages_required', false)) {
                return [
                    'goal' => 'Подтвердить запуск заказа в работу без требования оплаты.',
                    'questions' => [],
                    'conditions' => 'Перейти в производство/работу после согласования условий. Оплату не требовать.',
                    'allowed_actions' => $baseActions,
                    'assignee_department_id' => $fallbackDepartmentId,
                    'manager_confirmation' => false,
                ];
            }

            return [
                'goal' => 'Корректно обработать оплату, реквизиты или перенос оплаты без повторных вопросов.',
                'questions' => ['Нужны ли реквизиты?', 'Когда удобно оплатить?'],
                'conditions' => 'Если клиент сообщил, что оплатил, перейти к следующему этапу. Если просит реквизиты или оплатит позже, создать задачу менеджеру.',
                'allowed_actions' => $baseActions,
                'assignee_department_id' => $fallbackDepartmentId,
                'manager_confirmation' => false,
            ];
        }

        if (
            $this->nameMatches($name, ['достав', 'монтаж', 'готов'])
            || $stageType === FunnelStageType::DELIVERY
        ) {
            return [
                'goal' => 'Согласовать финальную доставку, монтаж, выдачу или подтвердить выполнение.',
                'questions' => ['Удобный день и время', 'Адрес и контакт на месте', 'Есть ли ограничения по доступу'],
                'conditions' => 'Перейти дальше, когда клиент указал дату и время или подтвердил успешное выполнение.',
                'allowed_actions' => $baseActions,
                'assignee_department_id' => $fallbackDepartmentId,
                'manager_confirmation' => false,
            ];
        }

        if (
            $isFinal
            || $stageType === FunnelStageType::DONE
            || $this->nameMatches($name, ['закрыто', 'выполн'])
        ) {
            return [
                'goal' => 'Финальный этап. Поблагодарить клиента и не продолжать активные касания без нового вопроса.',
                'questions' => [],
                'conditions' => 'Финальный этап. Если клиент задаёт новый вопрос, обработать его как новый запрос.',
                'allowed_actions' => [
                    FunnelStageAiRule::ACTION_REPLY_CUSTOMER,
                    FunnelStageAiRule::ACTION_NOTIFY_MANAGER,
                ],
                'assignee_department_id' => $fallbackDepartmentId,
                'manager_confirmation' => false,
            ];
        }

        if ($stageType === FunnelStageType::PRODUCTION) {
            return [
                'goal' => 'Информировать о статусе изготовления или работ без выдуманных сроков.',
                'questions' => [],
                'conditions' => 'Перейти дальше, когда заказ готов или клиент получил актуальный статус.',
                'allowed_actions' => $baseActions,
                'assignee_department_id' => $fallbackDepartmentId,
                'manager_confirmation' => false,
            ];
        }

        return [
            'goal' => 'Понять запрос клиента и продвинуть его к следующему шагу воронки.',
            'questions' => ['Что именно интересует?', 'Какие сроки удобны?', 'Есть ли важные условия или ограничения?'],
            'conditions' => 'Перейти дальше, когда собраны ключевые данные для следующего этапа. Не спрашивать повторно то, что клиент уже написал.',
            'allowed_actions' => $baseActions,
            'assignee_department_id' => $fallbackDepartmentId,
            'manager_confirmation' => false,
        ];
    }

    private function resolveFallbackDepartmentId(int $companyId): ?int
    {
        $department = Department::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->first(['id']);

        return $department?->id;
    }

    /**
     * @param  list<string>  $needles
     */
    private function nameMatches(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if ($needle !== '' && str_contains($haystack, mb_strtolower($needle))) {
                return true;
            }
        }

        return false;
    }
}
