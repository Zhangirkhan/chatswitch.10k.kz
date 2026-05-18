<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AiMessageRating;
use App\Models\AiOrchestratorAction;
use App\Models\AiOrchestratorRun;
use App\Models\AiResponseLog;
use App\Models\Chat;
use App\Models\Department;
use App\Models\Funnel;
use App\Models\FunnelStage;
use App\Models\FunnelStageAiRule;
use App\Models\KnowledgeRule;
use App\Models\Product;
use App\Models\Service;
use App\Models\SystemSetting;
use App\Services\AI\AiReadinessService;
use App\Services\AI\AiSimulationService;
use App\Services\AI\ChatAttentionService;
use App\Support\TenantCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

final class AiInsightsController extends Controller
{
    public function __construct(
        private readonly AiReadinessService $readinessService,
        private readonly ChatAttentionService $chatAttention,
        private readonly AiSimulationService $simulationService,
    ) {}

    public function index(): Response
    {
        abort_unless(
            SystemSetting::getValue('module_ai_quality', 'on') === 'on',
            403,
            'Модуль «AI и качество» отключён администратором.',
        );

        $failedLogs = [];
        if (Schema::hasTable('ai_response_logs')) {
            $failedLogs = AiResponseLog::query()
                ->whereIn('status', ['failed', 'blocked'])
                ->with(['chat:id,chat_name', 'company:id,name'])
                ->orderByDesc('created_at')
                ->limit(50)
                ->get()
                ->map(static fn (AiResponseLog $log): array => [
                    'id' => $log->id,
                    'created_at' => $log->created_at?->toIso8601String(),
                    'status' => $log->status,
                    'mode' => $log->mode,
                    'error' => $log->error,
                    'chat' => $log->chat?->chat_name ?? 'Чат #'.$log->chat_id,
                    'company' => $log->company?->name,
                ])
                ->values()
                ->all();
        }

        $problemRatings = [];
        if (Schema::hasTable('ai_message_ratings')) {
            $problemRatings = AiMessageRating::query()
                ->whereIn('rating', ['style', 'facts', 'long', 'context'])
                ->with([
                    'user:id,name',
                    'message' => static function ($query): void {
                        $query->select('id', 'chat_id', 'body')
                            ->with(['chat:id,chat_name']);
                    },
                ])
                ->orderByDesc('created_at')
                ->limit(50)
                ->get()
                ->map(static function (AiMessageRating $row): array {
                    $body = (string) ($row->message?->body ?? '');
                    $preview = mb_strlen($body) > 160 ? mb_substr($body, 0, 160).'…' : $body;

                    return [
                        'id' => $row->id,
                        'rating' => $row->rating,
                        'created_at' => $row->created_at?->toIso8601String(),
                        'user' => $row->user?->name,
                        'chat' => $row->message?->chat?->chat_name ?? 'Чат',
                        'body_preview' => $preview,
                    ];
                })
                ->values()
                ->all();
        }

        return Inertia::render('Settings/AiQuality', [
            'readiness' => $this->readinessService->evaluate(),
            'orchestrator_metrics' => $this->orchestratorMetrics(),
            'configuration_audit' => $this->configurationAudit(),
            'improvement_suggestions' => $this->improvementSuggestions(),
            'attention_queue' => $this->chatAttention->queue(),
            'guardrail_events' => $this->guardrailEvents(),
            'failed_logs' => $failedLogs,
            'problem_ratings' => $problemRatings,
        ]);
    }

    public function simulate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'message' => ['required', 'string', 'min:2', 'max:2000'],
            'history' => ['nullable', 'string', 'max:5000'],
        ]);

        $companyId = TenantCompany::id();
        $message = trim((string) $data['message']);
        $history = trim((string) ($data['history'] ?? ''));

        try {
            $result = $this->simulationService->simulate($companyId, $message, $history);
        } catch (Throwable $e) {
            Log::warning('[ai-quality] simulation failed', [
                'company_id' => $companyId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'AI-симулятор временно недоступен. Попробуйте позже.',
                'technical_error' => $request->user()?->hasRole('administrator') === true ? $e->getMessage() : null,
            ], 502);
        }

        return response()->json([
            'result' => $result,
        ]);
    }

    /**
     * @return array{
     *     period_days: int,
     *     total_runs: int,
     *     completed: int,
     *     needs_manager: int,
     *     failed: int,
     *     skipped: int,
     *     avg_confidence: int|null,
     *     action_counts: list<array{type: string, label: string, count: int}>
     * }
     */
    private function orchestratorMetrics(): array
    {
        $companyId = TenantCompany::id();
        $since = now()->subDays(7);
        $runs = AiOrchestratorRun::query()
            ->where('company_id', $companyId)
            ->where('created_at', '>=', $since);

        $avgConfidence = (clone $runs)
            ->whereNotNull('confidence')
            ->avg('confidence');

        $actionLabels = [
            'reply_customer' => 'Ответы клиенту',
            'move_funnel_stage' => 'Переходы этапов',
            'create_appointment' => 'Записи в календарь',
            'assign_employee' => 'Назначения сотрудников',
            'notify_manager' => 'Передачи менеджеру',
            'create_task' => 'Созданные задачи',
        ];

        $actionCounts = AiOrchestratorAction::query()
            ->where('company_id', $companyId)
            ->where('created_at', '>=', $since)
            ->where('status', AiOrchestratorAction::STATUS_DONE)
            ->selectRaw('type, COUNT(*) as count')
            ->groupBy('type')
            ->pluck('count', 'type')
            ->map(fn ($count, string $type): array => [
                'type' => $type,
                'label' => $actionLabels[$type] ?? $type,
                'count' => (int) $count,
            ])
            ->sortByDesc('count')
            ->values()
            ->all();

        return [
            'period_days' => 7,
            'total_runs' => (clone $runs)->count(),
            'completed' => (clone $runs)->where('status', AiOrchestratorRun::STATUS_COMPLETED)->count(),
            'needs_manager' => (clone $runs)->where('status', AiOrchestratorRun::STATUS_NEEDS_MANAGER)->count(),
            'failed' => (clone $runs)->where('status', AiOrchestratorRun::STATUS_FAILED)->count(),
            'skipped' => (clone $runs)->where('status', AiOrchestratorRun::STATUS_SKIPPED)->count(),
            'avg_confidence' => $avgConfidence !== null ? (int) round(((float) $avgConfidence) * 100) : null,
            'action_counts' => $actionCounts,
        ];
    }

    /**
     * @return list<array{key: string, severity: string, category: string, title: string, description: string, action: string}>
     */
    private function configurationAudit(): array
    {
        $companyId = TenantCompany::id();
        $items = [];

        $emptyDepartments = Department::query()
            ->where('is_active', true)
            ->doesntHave('users')
            ->orderBy('name')
            ->limit(5)
            ->pluck('name')
            ->all();
        if ($emptyDepartments !== []) {
            $items[] = $this->auditItem(
                'empty_departments',
                'warning',
                'Команда',
                'Есть отделы без сотрудников',
                'AI может создать задачу или попытаться назначить отдел, где нет исполнителей: '.implode(', ', $emptyDepartments).'.',
                'Назначьте сотрудников в отделы или отключите пустые отделы.',
            );
        }

        $funnelsWithoutScenario = Funnel::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->whereDoesntHave('aiScenario', fn ($query) => $query->where('enabled', true))
            ->orderBy('name')
            ->limit(5)
            ->pluck('name')
            ->all();
        if ($funnelsWithoutScenario !== []) {
            $items[] = $this->auditItem(
                'funnels_without_scenario',
                'critical',
                'Воронки',
                'Активные воронки без AI-сценария',
                'AI сможет классифицировать этап, но не будет полноценно вести клиента в этих воронках: '.implode(', ', $funnelsWithoutScenario).'.',
                'Включите AI-сценарий для каждой активной воронки или отключите лишние воронки.',
            );
        }

        $stagesWithoutRules = FunnelStage::query()
            ->where('is_active', true)
            ->whereHas('funnel', fn ($query) => $query->where('company_id', $companyId)->where('is_active', true))
            ->whereDoesntHave('aiRule')
            ->with('funnel:id,name')
            ->orderBy('funnel_id')
            ->orderBy('position')
            ->limit(8)
            ->get()
            ->map(fn (FunnelStage $stage): string => $stage->funnel?->name.' / '.$stage->name)
            ->all();
        if ($stagesWithoutRules !== []) {
            $items[] = $this->auditItem(
                'stages_without_rules',
                'critical',
                'Правила',
                'Есть этапы без AI-правил',
                'На этих этапах AI не знает цель, вопросы и условия перехода: '.implode(', ', $stagesWithoutRules).'.',
                'Добавьте AI-правила к этапам в настройках воронок.',
            );
        }

        $weakRules = FunnelStageAiRule::query()
            ->where('company_id', $companyId)
            ->where(function ($query): void {
                $query->whereNull('transition_conditions')
                    ->orWhere('transition_conditions', '')
                    ->orWhereNull('allowed_actions')
                    ->orWhereJsonLength('allowed_actions', 0);
            })
            ->with('stage.funnel:id,name')
            ->limit(8)
            ->get()
            ->map(fn (FunnelStageAiRule $rule): string => ($rule->stage?->funnel?->name ?? 'Воронка').' / '.($rule->stage?->name ?? 'Этап'))
            ->all();
        if ($weakRules !== []) {
            $items[] = $this->auditItem(
                'weak_rules',
                'warning',
                'Правила',
                'Часть AI-правил неполная',
                'Не хватает условий перехода или разрешённых действий: '.implode(', ', $weakRules).'.',
                'Заполните условия перехода и allowed actions, чтобы AI не гадал.',
            );
        }

        $knowledgeCount = KnowledgeRule::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->where('include_in_prompt', true)
            ->count();
        if ($knowledgeCount < 5) {
            $items[] = $this->auditItem(
                'low_knowledge',
                'warning',
                'База знаний',
                'Мало правил в базе знаний',
                "Сейчас в промпт попадает правил: {$knowledgeCount}. Для стабильных ответов обычно нужно больше фактов про оплату, сроки, доставку, ограничения и тон.",
                'Добавьте 5-10 коротких правил по частым вопросам клиентов.',
            );
        }

        $placeholderProducts = Product::query()
            ->where('company_id', $companyId)
            ->where('include_in_prompt', true)
            ->where(function ($query): void {
                $query->where('name', 'like', '%Индивидуальный заказ%')
                    ->orWhere('sku', 'CUSTOM-ORDER')
                    ->orWhere('description', 'like', '%Уточните в настройках%');
            })
            ->count();
        if ($placeholderProducts > 0) {
            $items[] = $this->auditItem(
                'placeholder_catalog',
                'info',
                'Каталог',
                'В каталоге есть стартовые заглушки',
                'AI видит универсальный товар-заглушку. Это нормально для старта, но для реальных продаж лучше заменить его на настоящие категории.',
                'Добавьте реальные товары/услуги или отключите заглушку из промпта.',
            );
        }

        $catalogCount = Product::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->where('include_in_prompt', true)
            ->count()
            + Service::query()
                ->where('company_id', $companyId)
                ->where('is_active', true)
                ->where('include_in_prompt', true)
                ->count();
        if ($catalogCount < 3) {
            $items[] = $this->auditItem(
                'small_catalog',
                'info',
                'Каталог',
                'Мало товаров и услуг для AI',
                "Сейчас AI видит {$catalogCount} записей каталога. Он сможет отвечать, но будет чаще задавать уточняющие вопросы.",
                'Добавьте основные категории, услуги и ориентиры по условиям.',
            );
        }

        return $items;
    }

    /**
     * @return list<array{key: string, label: string, count: int, action: string, examples: list<array{chat: string, preview: string, created_at: string|null}>}>
     */
    private function improvementSuggestions(): array
    {
        if (! Schema::hasTable('ai_message_ratings')) {
            return [];
        }

        $labels = [
            'style' => [
                'label' => 'Настроить тон и стиль',
                'action' => 'Проверьте последние правки операторов и добавьте правило тона в базу знаний или обновите profile tone.',
            ],
            'facts' => [
                'label' => 'Уточнить факты в базе знаний',
                'action' => 'Добавьте точные цены, сроки, условия оплаты/доставки или запреты на обещания без менеджера.',
            ],
            'long' => [
                'label' => 'Сделать ответы короче',
                'action' => 'Добавьте правило: отвечать кратко, 1-3 предложения, без рекламных карточек без запроса клиента.',
            ],
            'context' => [
                'label' => 'Добавить контекст для AI',
                'action' => 'Добавьте недостающие товары, услуги или правила по частым вопросам клиентов.',
            ],
        ];

        return collect(array_keys($labels))
            ->map(function (string $rating) use ($labels): ?array {
                $query = AiMessageRating::query()
                    ->where('rating', $rating)
                    ->whereHas('message.chat', fn ($chatQuery) => $chatQuery->where('company_id', TenantCompany::id()));

                $count = (clone $query)->count();
                if ($count === 0) {
                    return null;
                }

                $examples = (clone $query)
                    ->with(['message' => fn ($messageQuery) => $messageQuery->select('id', 'chat_id', 'body')->with('chat:id,chat_name')])
                    ->latest('id')
                    ->limit(3)
                    ->get(['id', 'message_id', 'created_at'])
                    ->map(function (AiMessageRating $row): array {
                        $body = trim((string) ($row->message?->body ?? ''));

                        return [
                            'chat' => $row->message?->chat?->chat_name ?? 'Чат',
                            'preview' => mb_strlen($body) > 180 ? mb_substr($body, 0, 180).'…' : $body,
                            'created_at' => $row->created_at?->toIso8601String(),
                        ];
                    })
                    ->values()
                    ->all();

                return [
                    'key' => $rating,
                    'label' => $labels[$rating]['label'],
                    'count' => $count,
                    'action' => $labels[$rating]['action'],
                    'examples' => $examples,
                ];
            })
            ->filter()
            ->sortByDesc('count')
            ->values()
            ->all();
    }

    /**
     * @return array{key: string, severity: string, category: string, title: string, description: string, action: string}
     */
    private function auditItem(string $key, string $severity, string $category, string $title, string $description, string $action): array
    {
        return compact('key', 'severity', 'category', 'title', 'description', 'action');
    }

    /**
     * @return list<array{id: int, chat: string, reason: string|null, status: string, created_at: string|null}>
     */
    private function guardrailEvents(): array
    {
        if (! Schema::hasTable('ai_orchestrator_runs')) {
            return [];
        }

        return AiOrchestratorRun::query()
            ->where('company_id', TenantCompany::id())
            ->where(function ($query): void {
                $query->where('reason', 'like', 'AI остановлен:%')
                    ->orWhere('reason', 'like', '%антизацик%')
                    ->orWhere('reason', 'like', '%повтор%');
            })
            ->with('chat:id,chat_name')
            ->latest('id')
            ->limit(30)
            ->get(['id', 'chat_id', 'status', 'reason', 'created_at'])
            ->map(fn (AiOrchestratorRun $run): array => [
                'id' => $run->id,
                'chat' => $run->chat?->chat_name ?: 'Чат #'.$run->chat_id,
                'reason' => $run->reason,
                'status' => (string) $run->status,
                'created_at' => $run->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }
}
