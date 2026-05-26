<?php

declare(strict_types=1);

namespace App\Services\Company;

use App\Models\Company;
use App\Models\Department;
use App\Models\Funnel;
use App\Models\FunnelAiScenario;
use App\Models\FunnelStage;
use App\Models\FunnelStageAiRule;
use App\Models\KnowledgeRule;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Support\FunnelStageType;
use Illuminate\Support\Facades\DB;

final class CompanyOnboardingService
{
    public function bootstrap(Company $company, ?User $owner = null): void
    {
        DB::transaction(function () use ($company, $owner): void {
            $salesDepartment = $this->department($company, 'Отдел продаж', 'Первичная обработка клиентов, продажи и контроль сделок.');
            $operationsDepartment = $this->department($company, 'Операционный отдел', 'Исполнители, доставка, монтаж, сервис и выполнение заказов.');

            if ($owner instanceof User) {
                $owner->forceFill([
                    'company_id' => $company->id,
                    'department_id' => $owner->department_id ?: $salesDepartment->id,
                ])->save();
                $owner->departments()->syncWithoutDetaching([$salesDepartment->id]);
            }

            $funnel = $this->funnel($company);
            $stages = $this->stages($funnel);

            foreach ([$salesDepartment, $operationsDepartment] as $department) {
                $department->funnels()->syncWithoutDetaching([$funnel->id]);
            }

            $salesStageIds = collect($stages)
                ->reject(fn (FunnelStage $stage): bool => in_array($stage->name, [
                    'В работе',
                    'Готово к доставке/монтажу',
                    'Доставка/монтаж назначены',
                    'Заказ выполнен',
                ], true))
                ->pluck('id')
                ->all();
            $operationsStageIds = collect($stages)
                ->filter(fn (FunnelStage $stage): bool => in_array($stage->name, [
                    'В работе',
                    'Готово к доставке/монтажу',
                    'Доставка/монтаж назначены',
                    'Заказ выполнен',
                    'Закрыто успешно',
                ], true))
                ->pluck('id')
                ->all();

            $salesDepartment->funnelStages()->syncWithoutDetaching($salesStageIds);
            $operationsDepartment->funnelStages()->syncWithoutDetaching($operationsStageIds);

            $this->scenario($company, $funnel, $salesDepartment);
            $this->stageRules($company, $funnel, $stages, $salesDepartment, $operationsDepartment);
            $this->knowledge($company);
            $this->catalog($company);
        });
    }

    private function department(Company $company, string $name, string $description): Department
    {
        return Department::query()->withoutGlobalScope('tenant')->firstOrCreate(
            ['company_id' => $company->id, 'name' => $name],
            ['description' => $description, 'is_active' => true],
        );
    }

    private function funnel(Company $company): Funnel
    {
        $position = (int) Funnel::query()->where('company_id', $company->id)->max('position') + 1;

        return Funnel::query()->updateOrCreate(
            ['company_id' => $company->id, 'name' => 'Универсальная продажа'],
            [
                'description' => 'Базовая AI-воронка для обработки входящих лидов: от интереса до оплаты, выполнения и успешного закрытия.',
                'color' => '#0f766e',
                'is_active' => true,
                'position' => Funnel::query()
                    ->where('company_id', $company->id)
                    ->where('name', 'Универсальная продажа')
                    ->value('position') ?? $position,
            ],
        );
    }

    /**
     * @return array<string, FunnelStage>
     */
    private function stages(Funnel $funnel): array
    {
        $definitions = [
            ['Новый интерес', '#2563eb'],
            ['Квалификация', '#0ea5e9'],
            ['Расчёт/предложение', '#7c3aed'],
            ['Коммерческое предложение отправлено', '#8b5cf6'],
            ['Согласование условий', '#a855f7'],
            ['Ожидание предоплаты', '#eab308'],
            ['Предоплата получена', '#84cc16'],
            ['В работе', '#64748b'],
            ['Готово к доставке/монтажу', '#06b6d4'],
            ['Доставка/монтаж назначены', '#0891b2'],
            ['Заказ выполнен', '#10b981'],
            ['Закрыто успешно', '#15803d'],
            ['Нет ответа', '#94a3b8'],
            ['Отложенный интерес', '#f97316'],
            ['Закрыто неуспешно', '#991b1b'],
        ];

        $stages = [];
        foreach ($definitions as $position => [$name, $color]) {
            $stages[$name] = FunnelStage::query()->updateOrCreate(
                ['funnel_id' => $funnel->id, 'name' => $name],
                [
                    'color' => $color,
                    'stage_type' => FunnelStageType::guessFromName($name),
                    'position' => $position,
                    'is_active' => true,
                ],
            );
        }

        return $stages;
    }

    private function scenario(Company $company, Funnel $funnel, Department $fallbackDepartment): void
    {
        FunnelAiScenario::query()->updateOrCreate(
            ['funnel_id' => $funnel->id],
            [
                'company_id' => $company->id,
                'enabled' => true,
                'customer_identity' => 'company',
                'booking_horizon_days' => 30,
                'fallback_manager_user_id' => null,
                'fallback_department_id' => $fallbackDepartment->id,
                'manager_confirmation_required' => false,
            ],
        );
    }

    /**
     * @param  array<string, FunnelStage>  $stages
     */
    private function stageRules(Company $company, Funnel $funnel, array $stages, Department $salesDepartment, Department $operationsDepartment): void
    {
        foreach ($this->ruleDefinitions($salesDepartment, $operationsDepartment) as $stageName => $definition) {
            $stage = $stages[$stageName] ?? null;
            if (! $stage instanceof FunnelStage) {
                continue;
            }

            FunnelStageAiRule::query()->updateOrCreate(
                ['funnel_stage_id' => $stage->id],
                [
                    'company_id' => $company->id,
                    'funnel_id' => $funnel->id,
                    'goal' => $definition['goal'],
                    'required_questions' => $definition['questions'],
                    'transition_conditions' => $definition['conditions'],
                    'allowed_actions' => $definition['actions'],
                    'assignee_user_ids' => [],
                    'assignee_department_id' => $definition['department_id'] ?? null,
                    'require_manager_confirmation' => (bool) ($definition['manager_confirmation'] ?? false),
                ],
            );
        }
    }

    /**
     * @return array<string, array{goal: string, questions: list<string>, conditions: string, actions: list<string>, department_id?: int, manager_confirmation?: bool}>
     */
    private function ruleDefinitions(Department $salesDepartment, Department $operationsDepartment): array
    {
        $replyMoveTask = [
            FunnelStageAiRule::ACTION_REPLY_CUSTOMER,
            FunnelStageAiRule::ACTION_MOVE_FUNNEL_STAGE,
            FunnelStageAiRule::ACTION_NOTIFY_MANAGER,
            FunnelStageAiRule::ACTION_CREATE_TASK,
        ];
        $replyMoveSchedule = [
            ...$replyMoveTask,
            FunnelStageAiRule::ACTION_CREATE_APPOINTMENT,
            FunnelStageAiRule::ACTION_ASSIGN_EMPLOYEE,
        ];

        return [
            'Новый интерес' => [
                'goal' => 'Понять, что нужно клиенту, и начать квалификацию без давления.',
                'questions' => ['Что именно интересует?', 'Для какой задачи или ситуации нужен продукт/услуга?', 'Есть ли пожелания по срокам или бюджету?'],
                'conditions' => 'Перейти к квалификации, когда понятен предмет интереса клиента. Не переспрашивать то, что клиент уже написал.',
                'actions' => $replyMoveTask,
                'department_id' => $salesDepartment->id,
            ],
            'Квалификация' => [
                'goal' => 'Собрать параметры, адрес/локацию, сроки, бюджет и ограничения для расчёта или записи.',
                'questions' => ['Ключевые параметры заказа', 'Адрес/город/район, если нужен выезд или доставка', 'Удобные сроки или время', 'Бюджет или важные ограничения'],
                'conditions' => 'Перейти к расчёту/предложению, когда достаточно данных для предварительного ответа. Если клиент уже дал часть данных, не задавать их повторно.',
                'actions' => $replyMoveSchedule,
                'department_id' => $salesDepartment->id,
            ],
            'Расчёт/предложение' => [
                'goal' => 'Дать ориентир по стоимости/условиям или сообщить, что точное предложение готовится.',
                'questions' => ['Нужно ли уточнить материалы, комплектацию, объём или сроки?'],
                'conditions' => 'Перейти к КП отправлено, когда клиент получил ориентир или предложение.',
                'actions' => $replyMoveSchedule,
                'department_id' => $salesDepartment->id,
            ],
            'Коммерческое предложение отправлено' => [
                'goal' => 'Получить реакцию клиента на цену, условия и комплектацию.',
                'questions' => ['Подходит ли предложение?', 'Нужно ли что-то изменить по цене, срокам или комплектации?'],
                'conditions' => 'Если клиент согласен, перейти к согласованию условий. Если есть возражения, уточнить их один раз и передать менеджеру при необходимости.',
                'actions' => $replyMoveTask,
                'department_id' => $salesDepartment->id,
            ],
            'Согласование условий' => [
                'goal' => 'Довести клиента до договора/оплаты без самостоятельных обещаний по скидкам и нестандартным условиям.',
                'questions' => ['Удобно оформить договор онлайн или в офисе?', 'На кого оформить заказ?', 'Нужны ли реквизиты или ссылка для оплаты?'],
                'conditions' => 'Перейти к ожиданию предоплаты, когда условия согласованы. Скидки, рассрочки и нестандартные условия передавать менеджеру.',
                'actions' => $replyMoveTask,
                'department_id' => $salesDepartment->id,
                'manager_confirmation' => true,
            ],
            'Ожидание предоплаты' => [
                'goal' => 'Корректно обработать оплату или запрос реквизитов, не повторяя вопросы.',
                'questions' => ['Нужны ли реквизиты или ссылка для оплаты?', 'Когда удобно внести предоплату?'],
                'conditions' => 'Если клиент сообщил, что оплатил/внёс предоплату, перейти к Предоплата получена. Если просит реквизиты или оплатит позже, зафиксировать один раз и создать задачу.',
                'actions' => $replyMoveTask,
                'department_id' => $salesDepartment->id,
            ],
            'Предоплата получена' => [
                'goal' => 'Подтвердить запуск заказа в работу после оплаты.',
                'questions' => ['Нужно ли сообщить ориентировочный срок готовности?'],
                'conditions' => 'Перейти в работу после подтверждения оплаты/запуска.',
                'actions' => $replyMoveSchedule,
                'department_id' => $operationsDepartment->id,
            ],
            'В работе' => [
                'goal' => 'Информировать клиента о статусе без выдумывания сроков.',
                'questions' => ['Нужно ли уточнить срок готовности у ответственного?'],
                'conditions' => 'Перейти к готовности, когда сотрудник подтвердил готовность заказа/услуги.',
                'actions' => $replyMoveTask,
                'department_id' => $operationsDepartment->id,
                'manager_confirmation' => true,
            ],
            'Готово к доставке/монтажу' => [
                'goal' => 'Согласовать дату и время доставки, монтажа, выдачи или финального выполнения.',
                'questions' => ['На какой день и время удобно запланировать доставку/монтаж?', 'Есть ли ограничения по доступу, лифту, парковке или контакт на месте?'],
                'conditions' => 'Если клиент указал день и время, подтвердить запись и перейти к Доставка/монтаж назначены. Не спрашивать повторно уже указанные ограничения.',
                'actions' => $replyMoveTask,
                'department_id' => $operationsDepartment->id,
            ],
            'Доставка/монтаж назначены' => [
                'goal' => 'Подтвердить финальное выполнение и закрыть заказ при позитивном отзыве.',
                'questions' => ['Всё ли выполнено и устроило клиента?'],
                'conditions' => 'Если клиент благодарит и подтверждает, что всё выполнено/понравилось, перейти к Закрыто успешно.',
                'actions' => $replyMoveTask,
                'department_id' => $operationsDepartment->id,
            ],
            'Заказ выполнен' => [
                'goal' => 'Попросить обратную связь и закрыть сделку.',
                'questions' => ['Всё ли устроило?', 'Можно ли попросить отзыв?'],
                'conditions' => 'Перейти к Закрыто успешно после позитивного подтверждения клиента.',
                'actions' => $replyMoveTask,
                'department_id' => $salesDepartment->id,
            ],
            'Закрыто успешно' => [
                'goal' => 'Финальный успешный этап. Не продолжать активные касания без нового вопроса клиента.',
                'questions' => [],
                'conditions' => 'Финальный этап.',
                'actions' => [FunnelStageAiRule::ACTION_REPLY_CUSTOMER, FunnelStageAiRule::ACTION_NOTIFY_MANAGER],
                'department_id' => $salesDepartment->id,
            ],
            'Нет ответа' => [
                'goal' => 'Аккуратно вернуть клиента в диалог.',
                'questions' => ['Актуален ли ещё запрос?'],
                'conditions' => 'Вернуть в активный этап при ответе клиента.',
                'actions' => $replyMoveTask,
                'department_id' => $salesDepartment->id,
            ],
            'Отложенный интерес' => [
                'goal' => 'Зафиксировать будущий интерес и создать follow-up.',
                'questions' => ['Когда удобно вернуться к вопросу?'],
                'conditions' => 'Создать задачу и вернуться в активный этап при новом сообщении.',
                'actions' => $replyMoveTask,
                'department_id' => $salesDepartment->id,
            ],
            'Закрыто неуспешно' => [
                'goal' => 'Финальный неуспешный этап. Не давить на клиента.',
                'questions' => [],
                'conditions' => 'Финальный этап.',
                'actions' => [FunnelStageAiRule::ACTION_REPLY_CUSTOMER, FunnelStageAiRule::ACTION_NOTIFY_MANAGER],
                'department_id' => $salesDepartment->id,
                'manager_confirmation' => true,
            ],
        ];
    }

    private function knowledge(Company $company): void
    {
        $rules = [
            ['AI: не повторять клиента', 'ai_guardrail', 5, 'Никогда не отвечай клиенту его же вопросом или фразой. Если не знаешь точный ответ, скажи, что уточнишь у менеджера, и создай задачу.'],
            ['AI: не спрашивать уже предоставленные данные', 'ai_guardrail', 10, 'Перед вопросом проверь историю. Если клиент уже указал изделие, адрес, дату, время, оплату или ограничения, не спрашивай это повторно.'],
            ['AI: оплата и предоплата', 'payment', 20, 'Если клиент пишет, что оплатил или внёс предоплату, зафиксируй это и переведи заказ дальше. Не спрашивай реквизиты повторно. Получение оплаты подтверждает менеджер или бухгалтерия.'],
            ['AI: сроки и готовность', 'fulfillment', 30, 'Не выдумывай точные сроки производства. Если срок неизвестен, сообщи, что уточнишь у менеджера. Если сотрудник сообщил, что заказ готов, согласуй доставку/монтаж.'],
            ['AI: завершение заказа', 'completion', 40, 'Если клиент благодарит и подтверждает, что заказ выполнен и всё понравилось, поблагодари, попроси отзыв и переведи сделку в успешное закрытие.'],
        ];

        foreach ($rules as [$title, $type, $priority, $content]) {
            KnowledgeRule::query()->updateOrCreate(
                ['company_id' => $company->id, 'title' => $title],
                [
                    'type' => $type,
                    'content' => $content,
                    'priority' => $priority,
                    'is_active' => true,
                    'include_in_prompt' => true,
                ],
            );
        }
    }

    private function catalog(Company $company): void
    {
        Product::query()->updateOrCreate(
            ['company_id' => $company->id, 'name' => 'Индивидуальный заказ'],
            [
                'sku' => 'CUSTOM-ORDER',
                'description' => 'Базовый тип заказа для новой компании. Уточните в настройках реальные товары, категории и цены.',
                'price' => null,
                'attributes' => ['requires_qualification' => true, 'price_note' => 'Стоимость зависит от параметров заказа.'],
                'is_active' => true,
                'include_in_prompt' => true,
                'sort_order' => 10,
            ],
        );

        foreach ([
            ['Консультация', 'Первичная консультация клиента и уточнение задачи.', 30],
            ['Расчёт стоимости', 'Подготовка предварительной или точной стоимости по параметрам клиента.', 60],
            ['Доставка/выполнение', 'Финальная доставка, монтаж, выдача или выполнение услуги.', 120],
        ] as $index => [$name, $description, $duration]) {
            Service::query()->updateOrCreate(
                ['company_id' => $company->id, 'name' => $name],
                [
                    'description' => $description,
                    'duration_minutes' => $duration,
                    'price' => null,
                    'conditions' => ['generic' => true],
                    'is_active' => true,
                    'include_in_prompt' => true,
                    'sort_order' => ($index + 1) * 10,
                ],
            );
        }
    }
}
