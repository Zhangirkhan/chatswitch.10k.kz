<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\CompanyPromotion;
use App\Models\Department;
use App\Models\Funnel;
use App\Models\FunnelAiScenario;
use App\Models\FunnelStage;
use App\Models\FunnelStageAiRule;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\AI\FunnelAiSuggestionService;
use App\Services\Funnel\FunnelAiBootstrapService;
use App\Support\FunnelStageType;
use App\Support\TenantCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Throwable;

/**
 * CRUD воронок продаж и их этапов. Все эндпоинты только для администратора —
 * см. middleware на роутах в `routes/web.php`. Этапы — отдельные ресурсы,
 * вложенные в воронку: единичные действия (create/update/destroy) и групповой
 * `reorderStages` для перестановки порядка drag-and-drop'ом.
 */
final class FunnelController extends Controller
{
    public function __construct(
        private readonly FunnelAiBootstrapService $funnelAiBootstrap,
    ) {}

    public function index(): Response
    {
        $this->ensureModuleEnabled();

        $companyId = TenantCompany::id();

        $funnels = Funnel::query()
            ->where('company_id', $companyId)
            ->with(['aiScenario', 'stages.aiRule'])
            ->withCount('stages')
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        return Inertia::render('Settings/Funnels', [
            'funnels' => $funnels,
            'funnelTemplates' => $this->industryTemplates(),
            'promotions' => CompanyPromotion::query()
                ->where('company_id', $companyId)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get()
                ->map(fn (CompanyPromotion $promo): array => PromotionController::serialize($promo)),
            'aiScenarioUsers' => User::query()
                ->where('company_id', $companyId)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'department_id']),
            'aiScenarioDepartments' => Department::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name']),
        ]);
    }

    public function storeTemplate(Request $request): JsonResponse
    {
        $this->ensureModuleEnabled();

        $data = $request->validate([
            'template_key' => ['required', 'string', 'max:80'],
        ]);

        $templates = collect($this->industryTemplates())->keyBy('key');
        $template = $templates->get((string) $data['template_key']);
        abort_if($template === null, 404, 'Шаблон не найден.');

        $companyId = TenantCompany::id();
        $fallbackDepartment = Department::query()->where('is_active', true)->orderBy('id')->first();

        $funnel = DB::transaction(function () use ($template, $companyId, $fallbackDepartment): Funnel {
            $nextPosition = (int) (Funnel::query()
                ->where('company_id', $companyId)
                ->max('position') ?? -1) + 1;

            $funnel = Funnel::create([
                'company_id' => $companyId,
                'name' => $this->uniqueFunnelName($companyId, (string) $template['name']),
                'description' => $template['description'],
                'color' => $template['color'],
                'is_active' => true,
                'position' => $nextPosition,
            ]);

            $this->funnelAiBootstrap->ensureScenario(
                $funnel,
                $fallbackDepartment?->id,
                true,
            );

            foreach ($template['stages'] as $position => $stageTemplate) {
                $stage = $funnel->stages()->create([
                    'name' => $stageTemplate['name'],
                    'color' => $stageTemplate['color'],
                    'stage_type' => $stageTemplate['stage_type'] ?? FunnelStageType::guessFromName($stageTemplate['name']),
                    'position' => $position,
                    'is_active' => true,
                ]);

                $this->funnelAiBootstrap->createStageRuleFromDefinition(
                    $funnel,
                    $stage,
                    (string) $stageTemplate['goal'],
                    $stageTemplate['questions'],
                    (string) $stageTemplate['conditions'],
                    $fallbackDepartment?->id,
                    (bool) ($stageTemplate['manager_confirmation'] ?? false),
                );
            }

            $departmentIds = Department::query()->where('is_active', true)->pluck('id')->all();
            if ($departmentIds !== []) {
                $funnel->departments()->syncWithoutDetaching($departmentIds);
                $funnel->stages()->get()->each(
                    fn (FunnelStage $stage) => $stage->departments()->syncWithoutDetaching($departmentIds),
                );
            }

            return $funnel;
        });

        $funnel->load(['aiScenario', 'stages.aiRule'])->loadCount('stages');

        return response()->json([
            'success' => true,
            'funnel' => $funnel,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->ensureModuleEnabled();
        $validated = $this->validateFunnel($request);
        $stagesPayload = $this->validateOptionalStages($request);
        $companyId = TenantCompany::id();
        $validated['company_id'] = $companyId;

        $funnel = DB::transaction(function () use ($validated, $stagesPayload, $companyId): Funnel {
            $nextPosition = (int) (Funnel::query()
                ->where('company_id', $companyId)
                ->max('position') ?? -1) + 1;

            $funnel = Funnel::create([
                ...$validated,
                'position' => $nextPosition,
            ]);

            foreach ($stagesPayload as $index => $stage) {
                $funnel->stages()->create([
                    'name' => $stage['name'],
                    'color' => $stage['color'] ?? '#9ca3af',
                    'stage_type' => FunnelStageType::normalize(
                        $stage['stage_type'] ?? FunnelStageType::guessFromName($stage['name']),
                    ),
                    'is_active' => $stage['is_active'] ?? true,
                    'position' => $index,
                ]);
            }

            $this->funnelAiBootstrap->bootstrapFunnel($funnel->load('stages'), false);

            return $funnel;
        });

        $funnel->load(['aiScenario', 'stages.aiRule'])->loadCount('stages');

        return response()->json(['success' => true, 'funnel' => $funnel]);
    }

    public function update(Request $request, Funnel $funnel): JsonResponse
    {
        $this->ensureModuleEnabled();
        $validated = $this->validateFunnel($request, $funnel);
        unset($validated['company_id']);

        $funnel->update($validated);
        $funnel->load('stages')->loadCount('stages');

        return response()->json(['success' => true, 'funnel' => $funnel]);
    }

    public function destroy(Funnel $funnel): JsonResponse
    {
        $this->ensureModuleEnabled();
        $funnel->delete();

        return response()->json(['success' => true]);
    }

    public function storeStage(Request $request, Funnel $funnel): JsonResponse
    {
        $this->ensureModuleEnabled();
        $validated = $this->validateStage($request);

        $stage = DB::transaction(function () use ($funnel, $validated): FunnelStage {
            $nextPosition = (int) ($funnel->stages()->max('position') ?? -1) + 1;

            $stage = $funnel->stages()->create([
                ...$validated,
                'position' => $nextPosition,
            ]);

            $totalStages = (int) $funnel->stages()->count();
            $departmentId = $funnel->aiScenario?->fallback_department_id
                ?? $this->funnelAiBootstrap->ensureScenario($funnel)->fallback_department_id;

            $this->funnelAiBootstrap->ensureStageRule(
                $funnel,
                $stage,
                $nextPosition,
                $totalStages,
                $departmentId,
            );

            return $stage;
        });

        $funnel->load(['aiScenario', 'stages.aiRule'])->loadCount('stages');

        return response()->json([
            'success' => true,
            'funnel' => $funnel,
            'stage' => $stage->fresh(['aiRule']),
        ]);
    }

    public function updateStage(Request $request, Funnel $funnel, FunnelStage $stage): JsonResponse
    {
        $this->ensureModuleEnabled();
        abort_if((int) $stage->funnel_id !== (int) $funnel->id, 404);

        $validated = $this->validateStage($request);

        $stage->update($validated);
        $funnel->load('stages')->loadCount('stages');

        return response()->json([
            'success' => true,
            'funnel' => $funnel,
            'stage' => $stage->fresh(),
        ]);
    }

    public function destroyStage(Funnel $funnel, FunnelStage $stage): JsonResponse
    {
        $this->ensureModuleEnabled();
        abort_if((int) $stage->funnel_id !== (int) $funnel->id, 404);

        $stage->delete();
        $funnel->load('stages')->loadCount('stages');

        return response()->json(['success' => true, 'funnel' => $funnel]);
    }

    public function reorderStages(Request $request, Funnel $funnel): JsonResponse
    {
        $this->ensureModuleEnabled();
        $validated = $request->validate([
            'stage_ids' => ['required', 'array', 'min:1'],
            'stage_ids.*' => ['integer', 'exists:funnel_stages,id'],
        ]);

        $orderedIds = array_values(array_unique(array_map('intval', $validated['stage_ids'])));

        DB::transaction(function () use ($funnel, $orderedIds): void {
            $ownIds = $funnel->stages()->pluck('id')->map(fn ($v) => (int) $v)->all();

            $position = 0;
            foreach ($orderedIds as $stageId) {
                if (! in_array($stageId, $ownIds, true)) {
                    continue;
                }
                FunnelStage::query()
                    ->where('id', $stageId)
                    ->update(['position' => $position]);
                $position++;
            }
        });

        $funnel->load('stages')->loadCount('stages');

        return response()->json(['success' => true, 'funnel' => $funnel]);
    }

    public function updateAiScenario(Request $request, Funnel $funnel): JsonResponse
    {
        $this->ensureModuleEnabled();

        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'customer_identity' => ['nullable', 'string', 'max:32'],
            'booking_horizon_days' => ['nullable', 'integer', 'min:1', 'max:60'],
            'fallback_manager_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'fallback_department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'manager_confirmation_required' => ['sometimes', 'boolean'],
        ]);

        $scenario = FunnelAiScenario::query()->updateOrCreate(
            ['funnel_id' => $funnel->id],
            [
                'company_id' => $funnel->company_id,
                'enabled' => (bool) $data['enabled'],
                'customer_identity' => $data['customer_identity'] ?? 'company',
                'booking_horizon_days' => (int) ($data['booking_horizon_days'] ?? 30),
                'fallback_manager_user_id' => $data['fallback_manager_user_id'] ?? null,
                'fallback_department_id' => $data['fallback_department_id'] ?? null,
                'manager_confirmation_required' => (bool) ($data['manager_confirmation_required'] ?? false),
            ],
        );

        return response()->json(['success' => true, 'scenario' => $scenario->fresh()]);
    }

    public function updateStageAiRule(Request $request, Funnel $funnel, FunnelStage $stage): JsonResponse
    {
        $this->ensureModuleEnabled();
        abort_if((int) $stage->funnel_id !== (int) $funnel->id, 404);

        $data = $request->validate([
            'goal' => ['nullable', 'string', 'max:4000'],
            'required_questions' => ['nullable', 'array', 'max:20'],
            'required_questions.*' => ['string', 'max:300'],
            'transition_conditions' => ['nullable', 'string', 'max:4000'],
            'allowed_actions' => ['nullable', 'array', 'max:10'],
            'allowed_actions.*' => ['string', 'max:48'],
            'assignee_user_ids' => ['nullable', 'array', 'max:50'],
            'assignee_user_ids.*' => ['integer', 'exists:users,id'],
            'assignee_department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'require_manager_confirmation' => ['sometimes', 'boolean'],
            'follow_up_enabled' => ['sometimes', 'boolean'],
            'follow_up_delay_hours' => ['nullable', 'integer', 'min:1', 'max:720'],
            'follow_up_message' => ['nullable', 'string', 'max:4000'],
            'follow_up_mode' => ['nullable', 'string', 'in:template,ab,ai'],
            'follow_up_message_b' => ['nullable', 'string', 'max:4000'],
            'follow_up_ab_ratio' => ['nullable', 'integer', 'min:0', 'max:100'],
            'follow_up_cooldown_hours' => ['nullable', 'integer', 'min:1', 'max:720'],
            'follow_up_max_count' => ['nullable', 'integer', 'min:1', 'max:10'],
            'follow_up_strategy' => ['nullable', 'string', 'in:off,manager_proposals,auto_cron'],
            'follow_up_silence_after' => ['nullable', 'string', 'in:inbound,outbound'],
            'follow_up_allowed_promos' => ['nullable', 'array', 'max:10'],
            'follow_up_allowed_promos.*.id' => ['nullable', 'string', 'max:64'],
            'follow_up_allowed_promos.*.label' => ['nullable', 'string', 'max:120'],
            'follow_up_allowed_promos.*.percent' => ['nullable', 'integer', 'min:1', 'max:100'],
            'follow_up_allowed_promos.*.valid_until' => ['nullable', 'date'],
            'follow_up_allowed_promos.*.note' => ['nullable', 'string', 'max:500'],
            'follow_up_promotion_ids' => ['nullable', 'array', 'max:20'],
            'follow_up_promotion_ids.*' => ['integer', 'exists:company_promotions,id'],
            'follow_up_use_promotions' => ['sometimes', 'boolean'],
        ]);

        $allowed = collect($data['allowed_actions'] ?? FunnelStageAiRule::DEFAULT_ALLOWED_ACTIONS)
            ->filter(fn ($value): bool => is_string($value) && in_array($value, FunnelStageAiRule::DEFAULT_ALLOWED_ACTIONS, true))
            ->values()
            ->all();

        $rule = FunnelStageAiRule::query()->updateOrCreate(
            ['funnel_stage_id' => $stage->id],
            [
                'company_id' => $funnel->company_id,
                'funnel_id' => $funnel->id,
                'goal' => $data['goal'] ?? null,
                'required_questions' => array_values($data['required_questions'] ?? []),
                'transition_conditions' => $data['transition_conditions'] ?? null,
                'allowed_actions' => $allowed,
                'assignee_user_ids' => array_values(array_unique(array_map('intval', $data['assignee_user_ids'] ?? []))),
                'assignee_department_id' => $data['assignee_department_id'] ?? null,
                'require_manager_confirmation' => (bool) ($data['require_manager_confirmation'] ?? false),
                'follow_up_enabled' => (bool) ($data['follow_up_enabled'] ?? false),
                'follow_up_delay_hours' => max(1, (int) ($data['follow_up_delay_hours'] ?? 24)),
                'follow_up_message' => $data['follow_up_message'] ?? null,
                'follow_up_mode' => $data['follow_up_mode'] ?? FunnelStageAiRule::FOLLOW_UP_MODE_TEMPLATE,
                'follow_up_message_b' => $data['follow_up_message_b'] ?? null,
                'follow_up_ab_ratio' => max(0, min(100, (int) ($data['follow_up_ab_ratio'] ?? 50))),
                'follow_up_cooldown_hours' => max(1, (int) ($data['follow_up_cooldown_hours'] ?? 72)),
                'follow_up_max_count' => max(1, min(10, (int) ($data['follow_up_max_count'] ?? 2))),
                'follow_up_strategy' => $data['follow_up_strategy'] ?? FunnelStageAiRule::FOLLOW_UP_STRATEGY_OFF,
                'follow_up_silence_after' => $data['follow_up_silence_after'] ?? FunnelStageAiRule::FOLLOW_UP_SILENCE_OUTBOUND,
                'follow_up_allowed_promos' => $this->normalizeAllowedPromos($data['follow_up_allowed_promos'] ?? null),
                'follow_up_promotion_ids' => array_values(array_unique(array_map(
                    'intval',
                    $data['follow_up_promotion_ids'] ?? [],
                ))),
                'follow_up_use_promotions' => (bool) ($data['follow_up_use_promotions'] ?? true),
            ],
        );

        return response()->json(['success' => true, 'rule' => $rule->fresh()]);
    }

    public function aiSuggest(Request $request, FunnelAiSuggestionService $service): JsonResponse
    {
        $this->ensureModuleEnabled();

        $validated = $request->validate([
            'business_description' => ['required', 'string', 'min:10', 'max:4000'],
        ]);

        try {
            $suggestion = $service->suggest($validated['business_description']);
        } catch (RuntimeException $e) {
            return $this->aiErrorResponse($request, $e, 'suggest');
        } catch (Throwable $e) {
            return $this->aiUnexpectedResponse($request, $e, 'suggest');
        }

        return response()->json([
            'success' => true,
            'suggestion' => $suggestion,
        ]);
    }

    public function aiOnboardingSuggest(Request $request, FunnelAiSuggestionService $service): JsonResponse
    {
        $this->ensureModuleEnabled();

        $validated = $request->validate([
            'target_audience' => ['required', 'string', 'min:10', 'max:2000'],
            'industry' => ['required', 'string', 'min:3', 'max:2000'],
            'business_description' => ['required', 'string', 'min:10', 'max:2000'],
            'clients_description' => ['required', 'string', 'min:10', 'max:2000'],
            'products_description' => ['required', 'string', 'min:10', 'max:2000'],
            'sales_process' => ['required', 'string', 'min:10', 'max:2000'],
        ]);

        $validated['company_id'] = TenantCompany::id();

        try {
            $result = $service->suggestVariants($validated);
        } catch (RuntimeException $e) {
            return $this->aiErrorResponse($request, $e, 'onboarding');
        } catch (Throwable $e) {
            return $this->aiUnexpectedResponse($request, $e, 'onboarding');
        }

        return response()->json([
            'success' => true,
            ...$result,
        ]);
    }

    private function ensureModuleEnabled(): void
    {
        abort_unless(
            SystemSetting::getValue('module_funnels', 'on') === 'on',
            403,
            'Модуль «Воронки продаж» отключён администратором.',
        );
    }

    /**
     * @return list<array{
     *     key: string,
     *     name: string,
     *     industry: string,
     *     description: string,
     *     color: string,
     *     stages: list<array{name: string, color: string, goal: string, questions: list<string>, conditions: string, manager_confirmation?: bool}>
     * }>
     */
    private function industryTemplates(): array
    {
        return [
            $this->template('furniture', 'Мебель / кухни', 'Мебель и кухни на заказ', 'Замер, проект, договор, производство, доставка и монтаж.', '#a16207', [
                ['Первичный запрос', '#fbbf24', 'Понять, какое изделие нужно клиенту.', ['Что планируете заказать?', 'Для какой комнаты?', 'Есть ли размеры или фото?'], 'Перейти к замеру, когда понятен запрос.'],
                ['Замер / консультация', '#f59e0b', 'Согласовать замер или консультацию.', ['Удобная дата и время', 'Адрес', 'Контактное лицо'], 'Перейти к проекту после согласования замера.'],
                ['Проект и расчёт', '#d97706', 'Подготовить проект и смету.', ['Подходит ли предварительный расчёт?', 'Что нужно изменить?'], 'Перейти к договору после согласования проекта.'],
                ['Договор и предоплата', '#ca8a04', 'Зафиксировать договорённости и оплату.', ['Нужны ли реквизиты?', 'Когда удобно оплатить?'], 'Перейти в производство после оплаты или подтверждения.'],
                ['Производство', '#84cc16', 'Информировать о статусе изготовления без выдуманных сроков.', [], 'Перейти к доставке, когда заказ готов.'],
                ['Доставка / монтаж', '#22c55e', 'Согласовать доставку и монтаж.', ['Удобный день и время', 'Адрес и контакт на месте'], 'Закрыть после успешного монтажа.'],
                ['Закрыто успешно', '#15803d', 'Финальный успешный этап.', [], 'Финальный этап.'],
            ]),
            $this->template('clinic', 'Клиника / медицина', 'Медицинская клиника', 'Запись, консультация, визит, лечение и повторный контакт.', '#0ea5e9', [
                ['Первичное обращение', '#38bdf8', 'Понять жалобу клиента и направить к подходящей услуге.', ['Что беспокоит?', 'К какому специалисту хотите попасть?', 'Когда удобно?'], 'Перейти к записи, когда понятна услуга или специалист.'],
                ['Запись на приём', '#3b82f6', 'Согласовать дату, время и специалиста.', ['Удобный день и время', 'Имя пациента', 'Контактный номер'], 'Перейти дальше после согласования времени.'],
                ['Приём назначен', '#6366f1', 'Подтвердить запись и напомнить условия визита.', [], 'Ждать визита или вопроса клиента.'],
                ['После приёма', '#8b5cf6', 'Собрать обратную связь и при необходимости предложить следующий шаг.', ['Всё ли прошло хорошо?', 'Нужна ли повторная запись?'], 'Закрыть успешно после позитивной обратной связи.'],
                ['Закрыто успешно', '#16a34a', 'Финальный успешный этап.', [], 'Финальный этап.'],
            ]),
            $this->template('autoservice', 'Автосервис', 'Автосервис', 'Заявка, диагностика, согласование ремонта, выполнение и выдача авто.', '#f97316', [
                ['Заявка клиента', '#fb923c', 'Понять проблему автомобиля.', ['Марка и модель авто', 'Что случилось?', 'Когда удобно подъехать?'], 'Перейти к диагностике, когда понятна проблема.'],
                ['Запись на диагностику', '#f97316', 'Согласовать слот диагностики.', ['Дата и время', 'Госномер или контакт', 'Нужно ли эвакуировать авто?'], 'Перейти дальше после записи.'],
                ['Диагностика проведена', '#f59e0b', 'Передать клиенту результат и ориентир по работам.', ['Подходит ли смета?', 'Согласны ли на ремонт?'], 'Перейти к согласованию ремонта после отправки сметы.'],
                ['Ремонт согласован', '#84cc16', 'Зафиксировать согласие и запустить работы.', [], 'Перейти в работу после согласия клиента.'],
                ['Авто готово', '#22c55e', 'Согласовать выдачу и оплату.', ['Когда удобно забрать?', 'Нужны ли закрывающие документы?'], 'Закрыть после выдачи авто.'],
                ['Закрыто успешно', '#15803d', 'Финальный успешный этап.', [], 'Финальный этап.'],
            ]),
            $this->template('education', 'Образование', 'Курсы / обучение', 'Лид, диагностика, подбор программы, оплата и старт обучения.', '#8b5cf6', [
                ['Интерес к курсу', '#a78bfa', 'Понять запрос и уровень клиента.', ['Какой курс интересует?', 'Какая цель обучения?', 'Есть ли текущий уровень?'], 'Перейти к подбору программы, когда понятна цель.'],
                ['Подбор программы', '#8b5cf6', 'Предложить подходящую программу или диагностику.', ['Формат обучения', 'Удобное расписание', 'Возраст ученика, если важно'], 'Перейти к консультации/пробному занятию.'],
                ['Пробное занятие', '#7c3aed', 'Согласовать пробный урок или консультацию.', ['День и время', 'Контакт ученика/родителя'], 'Перейти к оплате после интереса клиента.'],
                ['Ожидание оплаты', '#eab308', 'Отправить условия оплаты и зафиксировать решение.', ['Нужны ли реквизиты?', 'Когда удобно оплатить?'], 'Перейти к старту после оплаты.'],
                ['Старт обучения', '#22c55e', 'Подтвердить старт и организационные детали.', [], 'Закрыть успешно после запуска обучения.'],
                ['Закрыто успешно', '#15803d', 'Финальный успешный этап.', [], 'Финальный этап.'],
            ]),
            $this->template('real_estate', 'Недвижимость', 'Недвижимость', 'Подбор объекта, показ, условия, сделка и сопровождение.', '#0891b2', [
                ['Запрос на объект', '#22d3ee', 'Понять тип объекта и критерии.', ['Покупка или аренда?', 'Район', 'Бюджет', 'Сроки'], 'Перейти к подбору после критериев.'],
                ['Подбор вариантов', '#06b6d4', 'Предложить варианты и уточнить реакцию.', ['Какие варианты понравились?', 'Что важно изменить?'], 'Перейти к показу после выбора объекта.'],
                ['Показ назначен', '#0891b2', 'Согласовать показ объекта.', ['Удобная дата и время', 'Контакт для встречи'], 'Перейти к обсуждению условий после показа.'],
                ['Обсуждение условий', '#0f766e', 'Зафиксировать интерес, торг и документы.', ['Подходит ли цена?', 'Нужна ли ипотека/рассрочка?'], 'Нестандартные условия требуют менеджера.', true],
                ['Сделка в работе', '#16a34a', 'Сопроводить клиента до подписания.', [], 'Закрыть после подписания/оплаты.'],
                ['Закрыто успешно', '#15803d', 'Финальный успешный этап.', [], 'Финальный этап.'],
            ]),
            $this->template('delivery', 'Доставка / заказы', 'Доставка и заказы', 'Оформление заказа, оплата, сборка, доставка и закрытие.', '#22c55e', [
                ['Новый заказ', '#86efac', 'Понять состав заказа.', ['Что нужно заказать?', 'Количество', 'Адрес доставки'], 'Перейти к подтверждению после состава заказа.'],
                ['Подтверждение заказа', '#4ade80', 'Проверить состав, стоимость и адрес.', ['Подтверждаете заказ?', 'Удобное время доставки?'], 'Перейти к оплате/сборке после подтверждения.'],
                ['Ожидание оплаты', '#eab308', 'Обработать оплату или оплату при получении.', ['Как удобно оплатить?', 'Нужны ли реквизиты?'], 'Перейти к сборке после оплаты/подтверждения.'],
                ['Заказ собирается', '#84cc16', 'Информировать о сборке без выдумывания сроков.', [], 'Перейти к доставке после готовности.'],
                ['Доставка назначена', '#22c55e', 'Согласовать доставку и контакт на месте.', ['Время доставки', 'Контакт получателя', 'Ограничения по адресу'], 'Закрыть после успешной доставки.'],
                ['Закрыто успешно', '#15803d', 'Финальный успешный этап.', [], 'Финальный этап.'],
            ]),
            $this->template('b2b_services', 'B2B услуги', 'B2B услуги', 'Квалификация лида, бриф, КП, согласование и запуск проекта.', '#64748b', [
                ['Входящий лид', '#94a3b8', 'Понять компанию, задачу и срочность.', ['Чем занимается компания?', 'Какая задача?', 'Какие сроки?'], 'Перейти к квалификации после понимания задачи.'],
                ['Квалификация', '#64748b', 'Оценить бюджет, ЛПР и применимость услуги.', ['Бюджет или ориентир', 'Кто принимает решение?', 'Есть ли текущий подрядчик?'], 'Перейти к брифу, если лид подходит.'],
                ['Бриф / созвон', '#3b82f6', 'Согласовать бриф или встречу.', ['Удобное время созвона', 'Кого подключить со стороны клиента?'], 'Перейти к КП после брифа.'],
                ['КП отправлено', '#8b5cf6', 'Получить реакцию на предложение.', ['Подходит ли предложение?', 'Что нужно изменить?'], 'Перейти к согласованию условий после интереса.'],
                ['Согласование договора', '#f59e0b', 'Согласовать условия без самостоятельных обещаний.', ['Реквизиты', 'Юридические правки', 'Дата старта'], 'Нестандартные условия требуют менеджера.', true],
                ['Проект запущен', '#16a34a', 'Подтвердить старт работ.', [], 'Закрыть успешно после запуска проекта.'],
                ['Закрыто успешно', '#15803d', 'Финальный успешный этап.', [], 'Финальный этап.'],
            ]),
        ];
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string, 3: list<string>, 4: string, 5?: bool}>  $stages
     * @return array{key: string, industry: string, name: string, description: string, color: string, stages: list<array{name: string, color: string, goal: string, questions: list<string>, conditions: string, manager_confirmation?: bool}>}
     */
    private function template(string $key, string $industry, string $name, string $description, string $color, array $stages): array
    {
        return [
            'key' => $key,
            'industry' => $industry,
            'name' => $name,
            'description' => $description,
            'color' => $color,
            'stages' => array_map(static fn (array $stage): array => [
                'name' => $stage[0],
                'color' => $stage[1],
                'stage_type' => FunnelStageType::guessFromName($stage[0]),
                'goal' => $stage[2],
                'questions' => $stage[3],
                'conditions' => $stage[4],
                'manager_confirmation' => (bool) ($stage[5] ?? false),
            ], $stages),
        ];
    }

    private function uniqueFunnelName(int $companyId, string $baseName): string
    {
        if (! Funnel::query()->where('company_id', $companyId)->where('name', $baseName)->exists()) {
            return $baseName;
        }

        for ($i = 2; $i <= 50; $i++) {
            $candidate = "{$baseName} {$i}";
            if (! Funnel::query()->where('company_id', $companyId)->where('name', $candidate)->exists()) {
                return $candidate;
            }
        }

        return $baseName.' '.now()->format('His');
    }

    /** @return array<string, mixed> */
    private function validateFunnel(Request $request, ?Funnel $existing = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'color' => ['nullable', 'string', 'max:16'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }

    /** @return array<string, mixed> */
    private function validateStage(Request $request): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'color' => ['nullable', 'string', 'max:16'],
            'stage_type' => ['nullable', 'string', 'max:32', Rule::in(FunnelStageType::values())],
            'is_active' => ['sometimes', 'boolean'],
            'wip_limit' => ['nullable', 'integer', 'min:1', 'max:999'],
        ]);

        $validated['stage_type'] = FunnelStageType::normalize(
            isset($validated['stage_type'])
                ? (string) $validated['stage_type']
                : FunnelStageType::guessFromName((string) $validated['name']),
        );

        return $validated;
    }

    /**
     * @return list<array{name: string, color?: string|null, is_active?: bool}>
     */
    private function validateOptionalStages(Request $request): array
    {
        if (! $request->has('stages')) {
            return [];
        }

        $validated = $request->validate([
            'stages' => ['array', 'max:20'],
            'stages.*.name' => ['required', 'string', 'max:255'],
            'stages.*.color' => ['nullable', 'string', 'max:16'],
            'stages.*.stage_type' => ['nullable', 'string', 'max:32', Rule::in(FunnelStageType::values())],
            'stages.*.is_active' => ['sometimes', 'boolean'],
        ]);

        return array_map(
            static function (array $stage): array {
                $stage['stage_type'] = FunnelStageType::normalize(
                    $stage['stage_type'] ?? FunnelStageType::guessFromName((string) $stage['name']),
                );

                return $stage;
            },
            array_values($validated['stages'] ?? []),
        );
    }

    private function aiErrorResponse(Request $request, RuntimeException $e, string $context): JsonResponse
    {
        Log::warning("[funnel-ai] {$context} failed", [
            'user_id' => $request->user()?->id,
            'error' => $e->getMessage(),
        ]);

        return response()->json([
            'success' => false,
            'message' => \App\Support\AiSafeErrorMessage::forUser(
                $e->getMessage(),
                $request->user()?->hasRole('administrator') === true,
            ),
        ], 422);
    }

    private function aiUnexpectedResponse(Request $request, Throwable $e, string $context): JsonResponse
    {
        Log::error("[funnel-ai] {$context} unexpected failure", [
            'user_id' => $request->user()?->id,
            'error' => $e->getMessage(),
        ]);

        return response()->json([
            'success' => false,
            'message' => 'Не удалось получить ответ AI. Попробуйте ещё раз.',
        ], 500);
    }

    /**
     * @param  mixed  $raw
     * @return list<array{id: string, label: string, percent: int|null, valid_until: string|null, note: string|null}>
     */
    private function normalizeAllowedPromos(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $result = [];
        foreach ($raw as $index => $item) {
            if (! is_array($item)) {
                continue;
            }
            $id = trim((string) ($item['id'] ?? ''));
            if ($id === '') {
                $id = 'promo_'.($index + 1);
            }
            $result[] = [
                'id' => $id,
                'label' => trim((string) ($item['label'] ?? '')),
                'percent' => isset($item['percent']) ? max(1, min(100, (int) $item['percent'])) : null,
                'valid_until' => isset($item['valid_until']) ? (string) $item['valid_until'] : null,
                'note' => trim((string) ($item['note'] ?? '')) ?: null,
            ];
        }

        return $result;
    }
}
