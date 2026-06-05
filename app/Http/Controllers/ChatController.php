<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Events\NewMessageReceived;
use App\Events\UserTyping;
use App\Http\Requests\Chat\CreateGroupRequest;
use App\Http\Requests\Chat\SendContactRequest;
use App\Http\Requests\Chat\SendMessageRequest;
use App\Http\Requests\Chat\SendPollRequest;
use App\Http\Requests\Chat\StartChatRequest;
use App\Http\Requests\Chat\SyncDepartmentsRequest;
use App\Http\Requests\Chat\ToggleMuteRequest;
use App\Http\Requests\Chat\UploadFileRequest;
use App\Jobs\SendOutboundMessageJob;
use App\Models\AiFollowUpProposal;
use App\Models\AiOrchestratorRun;
use App\Models\AiResponseLog;
use App\Models\CalendarEvent;
use App\Models\Chat;
use App\Models\ChatAssignment;
use App\Models\Contact;
use App\Models\Department;
use App\Models\DepartmentPost;
use App\Models\EmployeeToneProfile;
use App\Models\FunnelStage;
use App\Models\KnowledgeRule;
use App\Models\Message;
use App\Models\MessageMedia;
use App\Models\Product;
use App\Models\Service;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\WhatsappSession;
use App\Services\AI\AiFunnelOrchestratorService;
use App\Services\AI\AiReadinessService;
use App\Services\AI\AiSimulationService;
use App\Services\AI\ChatAttentionService;
use App\Services\AI\MessageAiDecisionService;
use App\Services\ChatService;
use App\Services\Funnel\ChatFunnelStateService;
use App\Services\Knowledge\ProductMessageAttachmentService;
use App\Services\OutboundChatMessageDispatcher;
use App\Services\WhatsappService;
use App\Support\AiSafeErrorMessage;
use App\Support\ChatUrl;
use App\Support\MediaType;
use App\Support\OperatorSignature;
use App\Support\OrganizationDepartmentTasks;
use App\Support\PhoneFormatter;
use App\Support\VCard;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

final class ChatController extends Controller
{
    /** Единый размер страницы списка чатов (Inertia + JSON feed для подгрузки). */
    private const int CHAT_LIST_PER_PAGE = 100;

    public function __construct(
        private readonly ChatService $chatService,
        private readonly WhatsappService $whatsappService,
        private readonly OutboundChatMessageDispatcher $outboundDispatcher,
        private readonly ChatAttentionService $chatAttention,
        private readonly MessageAiDecisionService $messageAiDecisions,
        private readonly AiSimulationService $aiSimulation,
        private readonly AiReadinessService $aiReadiness,
        private readonly AiFunnelOrchestratorService $aiOrchestrator,
    ) {}

    public function index(Request $request): Response
    {
        $listOwnership = $this->chatListOwnership($request);
        $listFilter = $this->chatListFilter($request);
        $chats = $this->chatService->getChatsForUser(
            $request->user(),
            $request->input('search'),
            $listOwnership,
            $listFilter,
        )
            ->where('is_archived', false)
            // Пустые оболочки групп после sync-groups не показываем — только чаты с перепиской.
            ->whereNotNull('last_message_at')
            ->paginate(self::CHAT_LIST_PER_PAGE);

        $this->chatService->enrichAttentionMeta($chats->items());

        return Inertia::render('Chats/Index', [
            'chats' => $chats,
            'search' => $request->input('search'),
            'listOwnership' => $listOwnership,
            'listFilter' => $listFilter,
            'attentionChatsTotal' => $this->chatAttention->countEligible(),
            'mineChatsTotal' => $this->mineChatsListTotal($request->user(), false),
        ]);
    }

    public function archivedIndex(Request $request): Response
    {
        $listOwnership = $this->chatListOwnership($request);
        $listFilter = $this->chatListFilter($request);
        $chats = $this->chatService->getChatsForUser(
            $request->user(),
            $request->input('search'),
            $listOwnership,
            $listFilter,
        )
            ->where('is_archived', true)
            ->paginate(self::CHAT_LIST_PER_PAGE);

        $this->chatService->enrichAttentionMeta($chats->items());

        return Inertia::render('Chats/Archived', [
            'chats' => $chats,
            'search' => $request->input('search'),
            'listOwnership' => $listOwnership,
            'listFilter' => $listFilter,
            'attentionChatsTotal' => $this->chatAttention->countEligible(),
            'mineChatsTotal' => $this->mineChatsListTotal($request->user(), true),
        ]);
    }

    /**
     * Подгрузка следующих страниц списка чатов (бесконечная прокрутка в сайдбаре).
     */
    public function feed(Request $request): JsonResponse
    {
        $page = max(1, (int) $request->query('page', 1));
        $archived = filter_var($request->query('archived', '0'), FILTER_VALIDATE_BOOLEAN);
        $searchRaw = $request->query('search');
        $search = is_string($searchRaw) ? trim($searchRaw) : null;
        if ($search === '') {
            $search = null;
        }

        $listOwnership = $this->chatListOwnership($request);
        $listFilter = $this->chatListFilter($request);
        $paginator = $this->chatService->getChatsForUser($request->user(), $search, $listOwnership, $listFilter)
            ->where('is_archived', $archived)
            ->when(! $archived, fn ($q) => $q->whereNotNull('last_message_at'))
            ->paginate(self::CHAT_LIST_PER_PAGE, ['*'], 'page', $page);

        $items = $paginator->items();
        $ensureChatId = max(0, (int) $request->query('ensure_chat_id', 0));
        if ($ensureChatId > 0 && ! collect($items)->contains(fn (Chat $row): bool => (int) $row->id === $ensureChatId)) {
            $extra = $this->chatService->getChatsForUser($request->user(), $search, $listOwnership, $listFilter)
                ->where('is_archived', $archived)
                ->when(! $archived, fn ($q) => $q->whereNotNull('last_message_at'))
                ->whereKey($ensureChatId)
                ->first();
            if ($extra instanceof Chat) {
                $items[] = $extra;
            }
        }

        $this->chatService->enrichAttentionMeta($items);

        return response()->json([
            'data' => $items,
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
        ]);
    }

    public function show(Request $request, Chat $chat): Response
    {
        $this->authorize('view', $chat);

        $chat->load([
            'contact',
            'company:id,name',
            'whatsappSession',
            'assignments.user',
            'departments',
            'aiResponder:id,name',
            'pinnedMessage' => function ($q) {
                $q->select([
                    'id',
                    'chat_id',
                    'direction',
                    'type',
                    'body',
                    'sender_name',
                    'sender_phone',
                    'sent_by_user_id',
                    'message_timestamp',
                ])->with([
                    'sentByUser:id,name',
                ]);
            },
        ]);
        $chat->setAttribute('can_manage_ai', $request->user()->can('manageAi', $chat));

        $funnelCatalog = [];
        if (SystemSetting::getValue('module_funnels', 'on') === 'on') {
            $chat->loadMissing(['funnel', 'funnelStage', 'departments']);
            $funnelState = app(ChatFunnelStateService::class);
            foreach ($funnelState->inertiaExtras($chat) as $key => $value) {
                $chat->setAttribute($key, $value);
            }
            $funnelCatalog = $funnelState->catalogForClient($chat);
        }

        $messages = $chat->messages()
            ->operatorVisible()
            ->with(OutboundChatMessageDispatcher::messageWithRelations())
            ->orderByDesc('message_timestamp')
            ->paginate(50);

        $this->messageAiDecisions->attachToMessages(collect($messages->items()));

        $listOwnership = $this->chatListOwnership($request);
        $listFilter = $this->chatListFilter($request);
        $sidebarChats = $this->sidebarChatsForShow($request, $chat, $listOwnership, $listFilter);

        // «Единая клиентская база»: все чаты того же клиента (contact), включая разные WA-номера.
        // Нужен, чтобы в панели контакта показывать: с этим человеком общались с WA #1 и WA #2.
        $contactChats = $chat->contact_id !== null
            ? Chat::with('whatsappSession:id,session_name,display_name,display_color,phone_number,status')
                ->where('contact_id', $chat->contact_id)
                ->orderByDesc('last_message_at')
                ->get([
                    'id', 'whatsapp_session_id', 'contact_id', 'chat_name',
                    'last_message_text', 'last_message_at', 'last_message_direction', 'last_message_is_ai',
                    'unread_count', 'is_archived',
                ])
            : collect();

        $aiReadinessBanner = null;
        $companyId = (int) ($chat->company_id ?? $request->user()->company_id ?? 0);
        if (
            SystemSetting::getValue('module_ai_quality', 'on') === 'on'
            && $companyId > 0
            && $request->user()->can('manageAi', $chat)
        ) {
            $readiness = $this->aiReadiness->evaluate($companyId);
            if ($readiness['score'] < AiReadinessService::READY_SCORE) {
                $aiReadinessBanner = [
                    'score' => $readiness['score'],
                    'threshold' => AiReadinessService::READY_SCORE,
                    'status' => $readiness['status'],
                    'label' => $readiness['label'],
                ];
            }
        }

        return Inertia::render('Chats/Show', [
            'chat' => $chat,
            'messages' => $messages,
            'chats' => $sidebarChats,
            'sidebarLazyLoad' => true,
            'contactChats' => $contactChats,
            'departments' => Department::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'assignableUsers' => $this->assignableUsersFor($request->user(), $chat),
            'aiStatus' => $this->latestAiStatus($chat, $request->user()),
            'pendingOrchestratorApproval' => $this->pendingOrchestratorApproval($chat),
            'pendingFollowUpProposal' => $this->pendingFollowUpProposal($chat),
            'sidebarInsights' => $this->sidebarInsights($chat),
            'listOwnership' => $listOwnership,
            'listFilter' => $listFilter,
            'attentionChatsTotal' => $this->chatAttention->countEligible(),
            'mineChatsTotal' => $this->mineChatsListTotal($request->user(), false),
            'funnelCatalog' => $funnelCatalog,
            'aiReadinessBanner' => $aiReadinessBanner,
        ]);
    }

    /**
     * @return array{
     *     events: list<array{id: int, title: string, starts_at: string|null, ends_at: string|null, assignee: string|null, source: string|null}>,
     *     tasks: list<array{id: int, title: string, body: string|null, status: string, created_at: string|null}>
     * }
     */
    private function sidebarInsights(Chat $chat): array
    {
        return Cache::remember(
            'chat.show.sidebar-insights.'.$chat->id,
            now()->addSeconds(20),
            fn (): array => $this->buildSidebarInsights($chat),
        );
    }

    /**
     * @return array{
     *     events: list<array{id: int, title: string, starts_at: string|null, ends_at: string|null, assignee: string|null, source: string|null}>,
     *     tasks: list<array{id: int, title: string, body: string|null, status: string, created_at: string|null}>
     * }
     */
    private function buildSidebarInsights(Chat $chat): array
    {
        $events = CalendarEvent::query()
            ->with('assignee:id,name')
            ->where('chat_id', $chat->id)
            ->where('starts_at', '>=', now()->subDay())
            ->orderBy('starts_at')
            ->limit(5)
            ->get(['id', 'title', 'starts_at', 'ends_at', 'assignee_user_id', 'source'])
            ->map(fn (CalendarEvent $event): array => [
                'id' => $event->id,
                'title' => $event->title,
                'starts_at' => $event->starts_at?->toIso8601String(),
                'ends_at' => $event->ends_at?->toIso8601String(),
                'assignee' => $event->assignee?->name,
                'source' => $event->source,
            ])
            ->values()
            ->all();

        $tasks = AiOrchestratorRun::query()
            ->where('chat_id', $chat->id)
            ->whereNotNull('plan')
            ->latest('id')
            ->limit(12)
            ->get(['id', 'status', 'plan', 'created_at'])
            ->map(function (AiOrchestratorRun $run): ?array {
                $title = data_get($run->plan, 'task.title');
                if (! is_string($title) || trim($title) === '') {
                    return null;
                }

                $body = data_get($run->plan, 'task.body');

                return [
                    'id' => $run->id,
                    'title' => mb_substr(trim($title), 0, 160),
                    'body' => is_string($body) ? mb_substr(trim($body), 0, 320) : null,
                    'status' => (string) $run->status,
                    'created_at' => $run->created_at?->toIso8601String(),
                ];
            })
            ->filter()
            ->take(5)
            ->values()
            ->all();

        return [
            'events' => $events,
            'tasks' => $tasks,
        ];
    }

    /** «Все» / «Мои» в списке чатов — только администратор и руководитель; синхронизируется через query `ownership`. */
    private function chatListFilter(Request $request): ?string
    {
        $filter = $request->input('filter');
        if ($filter === ChatAttentionService::FILTER_ATTENTION) {
            return ChatAttentionService::FILTER_ATTENTION;
        }

        return null;
    }

    private function chatListOwnership(Request $request): string
    {
        $user = $request->user();
        if ($user === null || ! $user->hasAnyRole(['administrator', 'manager'])) {
            return 'all';
        }

        return $request->query('ownership') === 'mine' ? 'mine' : 'all';
    }

    /** Сколько чатов в режиме «Мои» (назначенных на пользователя) в текущей вкладке активные/архив. */
    private function mineChatsListTotal(User $user, bool $isArchived): int
    {
        if (! $user->hasAnyRole(['administrator', 'manager'])) {
            return 0;
        }

        return (int) $this->chatService->getChatsForUser($user, null, 'mine')
            ->where('is_archived', $isArchived)
            ->count();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function latestAiStatus(Chat $chat, ?User $viewer): ?array
    {
        return Cache::remember(
            'chat.show.ai-status.'.$chat->id.'.'.($viewer?->id ?? 0),
            now()->addSeconds(20),
            fn (): ?array => $this->buildLatestAiStatus($chat, $viewer),
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function buildLatestAiStatus(Chat $chat, ?User $viewer): ?array
    {
        $orchestratorHistory = $this->aiOrchestratorHistory($chat);
        $logs = AiResponseLog::query()
            ->where('chat_id', $chat->id)
            ->whereIn('mode', ['auto', 'draft'])
            ->latest('id')
            ->limit(5)
            ->get(['id', 'mode', 'status', 'error', 'metadata', 'message_id', 'trigger_message_id', 'created_at', 'updated_at']);
        $log = $logs->first();

        if ($log === null && $orchestratorHistory === []) {
            return null;
        }
        if ($log === null) {
            return [
                'id' => 0,
                'mode' => 'orchestrator',
                'status' => 'completed',
                'label' => 'AI вёл сделку',
                'message' => 'AI-оркестратор уже выполнял действия по этому чату.',
                'hint' => 'Откройте историю ниже, чтобы увидеть причины и результаты шагов.',
                'knowledge_context' => $this->aiKnowledgeContextCounts($chat, $viewer),
                'tone_source' => $this->aiToneSource($chat, $viewer),
                'draft_reply' => null,
                'technical_error' => null,
                'message_id' => null,
                'trigger_message_id' => $orchestratorHistory[0]['trigger_message_id'] ?? null,
                'created_at' => $orchestratorHistory[0]['completed_at'] ?? null,
                'updated_at' => $orchestratorHistory[0]['completed_at'] ?? null,
                'history' => [],
                'orchestrator_history' => $orchestratorHistory,
            ];
        }

        $status = (string) $log->status;
        $error = is_string($log->error) ? $log->error : null;
        $isAdministrator = $viewer?->hasRole('administrator') === true;

        return [
            'id' => $log->id,
            'mode' => $log->mode,
            'status' => $status,
            'label' => $this->aiStatusLabel($status),
            'message' => $this->aiStatusMessage($status, $error, $isAdministrator),
            'hint' => $this->aiStatusHint($status),
            'knowledge_context' => $this->aiKnowledgeContextCounts($chat, $viewer),
            'tone_source' => $this->aiToneSource($chat, $viewer),
            'draft_reply' => is_string(data_get($log->metadata, 'draft_reply')) ? data_get($log->metadata, 'draft_reply') : null,
            'technical_error' => $isAdministrator ? $error : null,
            'message_id' => $log->message_id,
            'trigger_message_id' => $log->trigger_message_id,
            'created_at' => $log->created_at?->toIso8601String(),
            'updated_at' => $log->updated_at?->toIso8601String(),
            'history' => $logs
                ->map(fn (AiResponseLog $item): array => [
                    'id' => $item->id,
                    'mode' => $item->mode,
                    'status' => (string) $item->status,
                    'label' => $this->aiStatusLabel((string) $item->status),
                    'message' => $this->aiStatusMessage((string) $item->status, is_string($item->error) ? $item->error : null, $isAdministrator),
                    'technical_error' => $isAdministrator && is_string($item->error) ? $item->error : null,
                    'message_id' => $item->message_id,
                    'trigger_message_id' => $item->trigger_message_id,
                    'updated_at' => $item->updated_at?->toIso8601String(),
                ])
                ->values()
                ->all(),
            'orchestrator_history' => $orchestratorHistory,
        ];
    }

    /**
     * @return list<array{id: int, status: string, label: string, reason: string|null, confidence: float|null, target_stage: string|null, customer_reply: string|null, task_title: string|null, trigger_message_id: int|null, completed_at: string|null}>
     */
    /**
     * @return array{
     *     run_id: int,
     *     summary: string,
     *     appointment_label: string|null,
     *     stage_name: string|null,
     *     manager_note: string|null
     * }|null
     */
    /**
     * @return array<string, mixed>|null
     */
    private function pendingFollowUpProposal(Chat $chat): ?array
    {
        $proposal = AiFollowUpProposal::query()
            ->where('chat_id', $chat->id)
            ->where('status', AiFollowUpProposal::STATUS_NEEDS_MANAGER)
            ->latest('id')
            ->first();

        if ($proposal === null) {
            return null;
        }

        return ChatFollowUpProposalController::serializeProposal($proposal);
    }

    private function pendingOrchestratorApproval(Chat $chat): ?array
    {
        if ($chat->ai_orchestrator_status !== AiOrchestratorRun::STATUS_NEEDS_MANAGER) {
            return null;
        }

        $run = AiOrchestratorRun::query()
            ->where('chat_id', $chat->id)
            ->where('status', AiOrchestratorRun::STATUS_NEEDS_MANAGER)
            ->latest('id')
            ->first(['id', 'plan', 'reason']);

        if ($run === null) {
            return null;
        }

        $pending = data_get($run->plan, 'pending_plan');
        if (! is_array($pending) || $pending === []) {
            return null;
        }

        $appointmentLabel = null;
        $appointment = is_array($pending['appointment_request'] ?? null) ? $pending['appointment_request'] : null;
        if ($appointment !== null && is_string($appointment['starts_at'] ?? null)) {
            try {
                $startsAt = Carbon::parse($appointment['starts_at']);
                $time = $startsAt->format('H:i');
                $appointmentLabel = $startsAt->isToday()
                    ? 'сегодня в '.$time
                    : ($startsAt->isTomorrow() ? 'завтра в '.$time : $startsAt->locale('ru')->isoFormat('D MMMM').' в '.$time);
            } catch (Throwable) {
                $appointmentLabel = null;
            }
        }

        $stageName = null;
        $stageId = (int) ($pending['target_funnel_stage_id'] ?? 0);
        if ($stageId > 0) {
            $stageName = FunnelStage::query()->whereKey($stageId)->value('name');
        }

        $managerNote = is_string(data_get($run->plan, 'manager_note'))
            ? data_get($run->plan, 'manager_note')
            : null;

        $parts = array_filter([
            $appointmentLabel !== null ? 'Запись: замер '.$appointmentLabel : null,
            is_string($stageName) && $stageName !== '' ? 'Этап: '.$stageName : null,
        ]);

        return [
            'run_id' => $run->id,
            'summary' => $parts !== [] ? implode('. ', $parts) : 'AI предложил действие, требующее подтверждения.',
            'appointment_label' => $appointmentLabel,
            'stage_name' => is_string($stageName) ? $stageName : null,
            'manager_note' => $managerNote,
        ];
    }

    private function aiOrchestratorHistory(Chat $chat): array
    {
        return AiOrchestratorRun::query()
            ->where('chat_id', $chat->id)
            ->latest('id')
            ->limit(8)
            ->get(['id', 'status', 'reason', 'confidence', 'plan', 'trigger_message_id', 'completed_at', 'updated_at'])
            ->map(fn (AiOrchestratorRun $run): array => [
                'id' => $run->id,
                'status' => (string) $run->status,
                'label' => $this->aiOrchestratorStatusLabel((string) $run->status),
                'reason' => $run->reason,
                'confidence' => $run->confidence,
                'target_stage' => $this->targetStageNameFromPlan($run->plan),
                'customer_reply' => is_string(data_get($run->plan, 'customer_reply')) ? data_get($run->plan, 'customer_reply') : null,
                'task_title' => is_string(data_get($run->plan, 'task.title')) ? data_get($run->plan, 'task.title') : null,
                'trigger_message_id' => $run->trigger_message_id,
                'completed_at' => ($run->completed_at ?: $run->updated_at)?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    private function aiOrchestratorStatusLabel(string $status): string
    {
        return match ($status) {
            AiOrchestratorRun::STATUS_PENDING => 'AI ждёт обработки',
            AiOrchestratorRun::STATUS_RUNNING => 'AI выполняет шаг',
            AiOrchestratorRun::STATUS_COMPLETED => 'AI выполнил шаг',
            AiOrchestratorRun::STATUS_NEEDS_MANAGER => 'Нужен менеджер',
            AiOrchestratorRun::STATUS_SKIPPED => 'AI пропустил шаг',
            AiOrchestratorRun::STATUS_FAILED => 'AI-оркестратор упал',
            default => 'AI-оркестратор: '.$status,
        };
    }

    /**
     * @param  array<string, mixed>|null  $plan
     */
    private function targetStageNameFromPlan(?array $plan): ?string
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

    private function aiStatusLabel(string $status): string
    {
        return match ($status) {
            'pending' => 'AI в очереди',
            'generating' => 'AI готовит ответ',
            'drafted' => 'AI подготовил черновик',
            'sent' => 'AI ответил',
            'blocked' => 'AI остановлен проверкой',
            'failed' => 'AI недоступен',
            default => 'AI статус: '.$status,
        };
    }

    private function aiStatusMessage(string $status, ?string $error, bool $isAdministrator = false): string
    {
        return match ($status) {
            'pending' => 'AI получил задачу и скоро начнёт готовить ответ.',
            'generating' => 'AI сейчас готовит автоответ клиенту.',
            'drafted' => 'AI подготовил черновик ответа. Проверьте его и отправьте вручную, если он подходит.',
            'sent' => 'Последний автоответ AI успешно отправлен клиенту.',
            'blocked' => 'AI подготовил ответ, но он был остановлен проверкой безопасности.',
            'failed' => AiSafeErrorMessage::forUser($error, $isAdministrator),
            default => 'AI обновил статус обработки.',
        };
    }

    private function aiStatusHint(string $status): ?string
    {
        return match ($status) {
            'pending', 'generating' => 'Можно дождаться ответа или написать клиенту вручную.',
            'drafted' => 'Черновик не отправлен клиенту автоматически.',
            'sent' => 'Проверьте сообщение в истории чата, если нужно убедиться в формулировке.',
            'blocked' => 'Ответьте вручную или уточните базу знаний, если AI не должен блокироваться в таком сценарии.',
            'failed' => 'Ответьте вручную и передайте ошибку администратору, если она повторяется.',
            default => null,
        };
    }

    /**
     * @return array{rules: int, products: int, services: int}|null
     */
    private function aiKnowledgeContextCounts(Chat $chat, ?User $viewer): ?array
    {
        $companyId = $chat->company_id ?? $viewer?->company_id;
        if ($companyId === null) {
            return null;
        }

        return [
            'rules' => KnowledgeRule::query()
                ->where('company_id', $companyId)
                ->where('is_active', true)
                ->where('include_in_prompt', true)
                ->count(),
            'products' => Product::query()
                ->where('company_id', $companyId)
                ->where('is_active', true)
                ->where('include_in_prompt', true)
                ->count(),
            'services' => Service::query()
                ->where('company_id', $companyId)
                ->where('is_active', true)
                ->where('include_in_prompt', true)
                ->count(),
        ];
    }

    /**
     * @return array{source: string, label: string, hint: string}
     */
    private function aiToneSource(Chat $chat, ?User $viewer): array
    {
        $companyId = $chat->company_id ?? $viewer?->company_id;
        if ($companyId === null) {
            return [
                'source' => 'none',
                'label' => 'Тон: не собран',
                'hint' => 'Компания чата не определена.',
            ];
        }

        $responderId = $chat->ai_responder_user_id;
        if ($responderId !== null) {
            $employeeProfile = EmployeeToneProfile::query()
                ->where('company_id', $companyId)
                ->where('user_id', $responderId)
                ->first(['summary', 'metadata', 'phrases']);

            $hasEmployeeTone = $employeeProfile !== null
                && trim((string) $employeeProfile->summary) !== ''
                && (string) data_get($employeeProfile->metadata, 'source') !== 'fallback'
                && (int) data_get($employeeProfile->metadata, 'samples_count', 0) > 0;

            if ($hasEmployeeTone) {
                return [
                    'source' => 'employee',
                    'label' => 'Тон: сотрудник',
                    'hint' => 'AI использует личный профиль тона выбранного ответчика.',
                    'suggestion' => $this->toneProfileSuggestion($employeeProfile?->summary, $employeeProfile?->phrases),
                ];
            }
        }

        $companySummary = Schema::hasTable('company_tone_profiles')
            ? DB::table('company_tone_profiles')
                ->where('company_id', $companyId)
                ->value('summary')
            : null;
        if (trim((string) $companySummary) !== '') {
            return [
                'source' => 'company',
                'label' => 'Тон: компания',
                'hint' => 'Личный тон сотрудника не собран, AI использует общий стиль компании.',
                'suggestion' => $this->toneProfileSuggestion((string) $companySummary, null),
            ];
        }

        return [
            'source' => 'none',
            'label' => 'Тон: не собран',
            'hint' => 'AI использует нейтральный краткий стиль до накопления профиля тона.',
            'suggestion' => null,
        ];
    }

    /**
     * @param  list<string>|null  $phrases
     */
    private function toneProfileSuggestion(?string $summary, ?array $phrases): ?string
    {
        if (is_array($phrases)) {
            foreach ($phrases as $phrase) {
                $line = trim((string) $phrase);
                if ($line !== '') {
                    return 'Фраза из профиля: «'.Str::limit($line, 80, '…').'»';
                }
            }
        }

        $summary = trim((string) $summary);
        if ($summary === '') {
            return null;
        }

        return Str::limit($summary, 140, '…');
    }

    public function syncDepartments(SyncDepartmentsRequest $request, Chat $chat): JsonResponse
    {
        $oldIds = $chat->departments()->pluck('departments.id')->all();
        $newIds = array_values(array_unique(array_map('intval', $request->input('department_ids', []))));

        $chat->departments()->sync($newIds);
        $chat->load('departments');

        $this->chatService->logDepartmentChange($chat, $request->user(), $oldIds, $newIds);

        return response()->json([
            'success' => true,
            'departments' => $chat->departments()->get(['departments.id', 'departments.name']),
        ]);
    }

    public function departmentHistory(Request $request, Chat $chat): JsonResponse
    {
        $this->authorize('syncDepartments', $chat);

        $history = $chat->messages()
            ->where('direction', 'system')
            ->where('body', 'like', 'Отделы чата обновлены:%')
            ->orderByRaw('COALESCE(message_timestamp, created_at) DESC')
            ->orderByDesc('id')
            ->limit(100)
            ->get(['id', 'body', 'message_timestamp', 'created_at'])
            ->map(fn (Message $message) => [
                'id' => $message->id,
                'body' => $message->body,
                'at' => ($message->message_timestamp ?: $message->created_at)?->toIso8601String(),
            ])
            ->values();

        $current = $chat->departments()
            ->orderBy('departments.name')
            ->get(['departments.id', 'departments.name'])
            ->map(fn (Department $department) => [
                'id' => $department->id,
                'name' => $department->name,
            ])
            ->values();

        return response()->json([
            'current' => $current,
            'history' => $history,
        ]);
    }

    public function requestManagerAttention(Request $request, Chat $chat): JsonResponse
    {
        $this->authorize('view', $chat);

        $data = $request->validate([
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $note = trim((string) ($data['note'] ?? ''));
        if ($note === '') {
            $note = 'Нужно проверить чат клиента.';
        }

        $this->chatService->logSystemMessage($chat, "Передано менеджеру: {$note} Автор: ".$request->user()->name.'.');
        $post = $this->createChatTask($request, $chat, 'Проверить клиентский чат', $note);

        return response()->json([
            'success' => true,
            'task_id' => $post?->id,
        ]);
    }

    public function approveOrchestrator(Request $request, Chat $chat, AiOrchestratorRun $run): JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            abort(403);
        }

        if (! $user->can('manageAi', $chat) && ! $user->hasAnyRole(['administrator', 'manager'])) {
            abort(403);
        }

        $this->authorize('view', $chat);

        if ((int) $run->chat_id !== (int) $chat->id) {
            abort(404);
        }

        try {
            $this->aiOrchestrator->approvePendingRun($run, $user);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            Log::warning('[chat] orchestrator approval failed', [
                'chat_id' => $chat->id,
                'run_id' => $run->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['message' => 'Не удалось подтвердить действие AI.'], 502);
        }

        $chat->refresh();

        return response()->json([
            'success' => true,
            'chat' => [
                'ai_orchestrator_status' => $chat->ai_orchestrator_status,
                'ai_orchestrator_last_summary' => $chat->ai_orchestrator_last_summary,
            ],
            'pending_orchestrator_approval' => $this->pendingOrchestratorApproval($chat),
        ]);
    }

    public function simulateAi(Request $request, Chat $chat): JsonResponse
    {
        $this->authorize('view', $chat);

        $data = $request->validate([
            'message' => ['required', 'string', 'min:2', 'max:2000'],
            'history' => ['nullable', 'string', 'max:5000'],
        ]);

        $message = trim((string) $data['message']);
        $history = trim((string) ($data['history'] ?? ''));

        try {
            $result = $this->aiSimulation->simulateForChat($chat, $message, $history);
        } catch (Throwable $e) {
            Log::warning('[chat] ai simulation failed', [
                'chat_id' => $chat->id,
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

    public function createQuickTask(Request $request, Chat $chat): JsonResponse
    {
        $this->authorize('view', $chat);

        abort_unless(
            OrganizationDepartmentTasks::enabled(),
            403,
            'Задачи по отделам временно отключены.',
        );

        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'body' => ['nullable', 'string', 'max:2000'],
        ]);

        $title = trim((string) ($data['title'] ?? ''));
        $body = trim((string) ($data['body'] ?? ''));
        $title = $title !== '' ? $title : 'Задача по клиентскому чату';
        $body = $body !== '' ? $body : 'Проверьте чат и выполните следующий шаг по клиенту.';

        $post = $this->createChatTask($request, $chat, $title, $body);
        $this->chatService->logSystemMessage($chat, "Создана задача: {$title}. Автор: ".$request->user()->name.'.');

        return response()->json([
            'success' => true,
            'task_id' => $post?->id,
        ]);
    }

    private function createChatTask(Request $request, Chat $chat, string $title, string $body): ?DepartmentPost
    {
        $department = $this->taskDepartmentFor($request->user(), $chat);
        if (! $department instanceof Department) {
            return null;
        }

        $post = DepartmentPost::query()->create([
            'department_id' => $department->id,
            'author_id' => $request->user()->id,
            'title' => $title,
            'body' => $body."\n\nЧат: ".ChatUrl::show($chat),
            'status' => DepartmentPost::STATUS_OPEN,
            'due_at' => null,
        ]);

        $assigneeIds = $chat->assignments()->pluck('user_id')->all();
        if ($assigneeIds !== []) {
            $post->assignees()->sync($assigneeIds);
        }

        return $post;
    }

    private function taskDepartmentFor(User $user, Chat $chat): ?Department
    {
        $department = $chat->departments()->where('is_active', true)->orderBy('departments.id')->first();
        if ($department instanceof Department) {
            return $department;
        }

        if ($user->department instanceof Department && $user->department->is_active) {
            return $user->department;
        }

        return Department::query()->where('is_active', true)->orderBy('id')->first();
    }

    public function sendMessage(SendMessageRequest $request, Chat $chat): JsonResponse
    {
        $result = $this->outboundDispatcher->sendTextMessage(
            $request->user(),
            $chat,
            [
                'message' => $request->input('message'),
                'display_message' => $request->input('display_message'),
                'quoted_message_id' => $request->input('quoted_message_id'),
                'mentions' => $request->input('mentions'),
                'mentions_meta' => $request->input('mentions_meta'),
                'product_id' => $request->input('product_id'),
            ],
        );

        return response()->json([
            'success' => true,
            'message' => $result->message,
            'tone_profile_learning_scheduled' => $result->toneProfileLearningScheduled,
            'draft_edit_kind' => $result->draftEditKind,
        ]);
    }

    public function products(Request $request, Chat $chat, ProductMessageAttachmentService $products): JsonResponse
    {
        $this->authorize('view', $chat);

        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
        ]);

        return response()->json([
            'products' => $products->listForChat($chat, $data['q'] ?? null),
        ]);
    }

    public function typing(Request $request, Chat $chat): JsonResponse
    {
        $this->authorize('view', $chat);

        $user = $request->user();
        broadcast(new UserTyping($chat->id, $user->id, $user->name));

        $chat->load('whatsappSession');
        $this->whatsappService->setTyping(
            $chat->whatsappSession->session_name,
            $chat->whatsapp_chat_id,
            true,
        );

        return response()->json(['success' => true]);
    }

    public function markRead(Request $request, Chat $chat): JsonResponse
    {
        $this->authorize('view', $chat);

        $chat->update(['unread_count' => 0]);
        $chat->load('whatsappSession');
        $this->whatsappService->sendSeen(
            $chat->whatsappSession->session_name,
            $chat->whatsapp_chat_id,
        );

        return response()->json(['success' => true]);
    }

    public function togglePin(Chat $chat): JsonResponse
    {
        $this->authorize('manage', $chat);

        $chat->update(['is_pinned' => ! $chat->is_pinned]);

        return response()->json(['success' => true, 'is_pinned' => $chat->is_pinned]);
    }

    public function pinMessage(Request $request, Chat $chat): JsonResponse
    {
        $this->authorize('view', $chat);

        $data = $request->validate([
            'message_id' => ['required', 'integer', 'exists:messages,id'],
        ]);

        $message = Message::query()
            ->where('id', (int) $data['message_id'])
            ->where('chat_id', $chat->id)
            ->first();

        if (! $message) {
            return response()->json(['success' => false, 'error' => 'Сообщение не найдено в этом чате.'], 422);
        }

        $chat->update(['pinned_message_id' => $message->id]);
        $chat->loadMissing('pinnedMessage.sentByUser:id,name');

        return response()->json(['success' => true, 'pinned_message' => $chat->pinnedMessage]);
    }

    public function unpinMessage(Request $request, Chat $chat): JsonResponse
    {
        $this->authorize('view', $chat);

        $chat->update(['pinned_message_id' => null]);

        return response()->json(['success' => true]);
    }

    public function archive(Chat $chat): JsonResponse
    {
        $this->authorize('manage', $chat);

        $chat->update(['is_archived' => ! $chat->is_archived]);

        return response()->json(['success' => true, 'is_archived' => $chat->is_archived]);
    }

    public function toggleMute(ToggleMuteRequest $request, Chat $chat): JsonResponse
    {
        $shouldUnmute = $request->boolean('unmute') || $chat->is_muted;

        if ($shouldUnmute && ! $request->filled('duration')) {
            $chat->update(['is_muted' => false, 'muted_until' => null]);

            return response()->json(['success' => true, 'is_muted' => false, 'muted_until' => null]);
        }

        $mutedUntil = match ($request->input('duration', 'always')) {
            '8h' => now()->addHours(8),
            '1w' => now()->addWeek(),
            default => null,
        };

        $chat->update(['is_muted' => true, 'muted_until' => $mutedUntil]);

        return response()->json([
            'success' => true,
            'is_muted' => true,
            'muted_until' => $mutedUntil?->toISOString(),
        ]);
    }

    public function toggleFavorite(Chat $chat): JsonResponse
    {
        $this->authorize('manage', $chat);

        $chat->update(['is_favorite' => ! $chat->is_favorite]);

        return response()->json(['success' => true, 'is_favorite' => $chat->is_favorite]);
    }

    public function toggleUnread(Chat $chat): JsonResponse
    {
        $this->authorize('manage', $chat);

        $chat->update(['unread_count' => $chat->unread_count > 0 ? 0 : 1]);

        return response()->json(['success' => true, 'unread_count' => $chat->unread_count]);
    }

    public function clear(Chat $chat): JsonResponse
    {
        $this->authorize('manage', $chat);

        $chat->messages()->delete();
        $chat->update([
            'last_message_text' => null,
            'last_message_at' => null,
            'last_message_direction' => null,
            'unread_count' => 0,
        ]);

        return response()->json(['success' => true]);
    }

    public function saveContact(Request $request, Chat $chat): JsonResponse
    {
        $this->authorize('view', $chat);

        abort_if($chat->is_group, 422, 'Нельзя сохранять контакт для группы.');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
        ]);

        $name = trim((string) $data['name']);
        if ($name === '') {
            return response()->json(['success' => false, 'error' => 'Имя не может быть пустым.'], 422);
        }

        $chat->loadMissing('contact');

        $phone = $chat->contact?->phone_number;
        if (! $phone) {
            // Fallback: derive from whatsapp_chat_id for 1:1 chats
            $phone = PhoneFormatter::fromWhatsappId($chat->whatsapp_chat_id);
        }

        if (! $phone) {
            return response()->json(['success' => false, 'error' => 'Не удалось определить номер контакта.'], 422);
        }

        $contact = $chat->contact ?: $this->chatService->findOrCreateContactByPhone($phone);

        // Save exactly what operator entered.
        $contact->name = $name;
        $contact->saveQuietly();

        if (! $chat->contact_id) {
            $chat->update(['contact_id' => $contact->id]);
        }

        // Keep the UI chat title consistent with the saved contact name.
        // (Lists/side panels prefer `chat.chat_name`.)
        $chat->update(['chat_name' => $name]);

        return response()->json(['success' => true, 'contact' => $contact]);
    }

    public function contacts(Request $request): JsonResponse
    {
        $search = trim((string) $request->input('search', ''));
        $sessionId = (int) $request->input('whatsapp_session_id', 0);

        $query = Contact::query()->orderByRaw('COALESCE(name, push_name, phone_number) asc');

        if ($search !== '') {
            $digits = preg_replace('/\D/', '', $search);
            $query->where(function ($q) use ($search, $digits) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('push_name', 'like', "%{$search}%")
                    ->orWhere('phone_number', 'like', "%{$search}%");
                if (is_string($digits) && $digits !== '') {
                    $q->orWhere('whatsapp_id', 'like', "%{$digits}%");
                }
            });
        }

        $recentChats = $this->chatService->getChatsForUser($request->user())
            ->when($sessionId > 0, fn ($q) => $q->where('whatsapp_session_id', $sessionId))
            ->whereNotNull('contact_id')
            ->where('is_group', false)
            ->orderByDesc('last_message_at')
            ->limit(200)
            ->get(['contact_id', 'chat_name', 'last_message_at']);

        $recentContactIds = $recentChats
            ->pluck('contact_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
        $chatNameByContactId = $recentChats
            ->filter(fn (Chat $c) => $c->contact_id !== null && trim((string) $c->chat_name) !== '')
            ->mapWithKeys(fn (Chat $c) => [(int) $c->contact_id => trim((string) $c->chat_name)]);

        $contacts = $query->limit(300)->get();
        if ($recentContactIds !== []) {
            $priority = array_flip($recentContactIds);
            $contacts = $contacts
                ->sortBy(fn (Contact $c) => $priority[$c->id] ?? PHP_INT_MAX)
                ->values();
        }
        $contacts = $contacts
            ->map(function (Contact $c) use ($chatNameByContactId) {
                $saved = trim((string) ($c->name ?? ''));
                $chatName = trim((string) ($chatNameByContactId[$c->id] ?? ''));
                $push = trim((string) ($c->push_name ?? ''));
                $phone = trim((string) ($c->phone_number ?? ''));
                $savedLooksLikeWaNick = $saved !== '' && $push !== '' && mb_strtolower($saved) === mb_strtolower($push);
                $displayName = $saved !== '' && ! $savedLooksLikeWaNick
                    ? $saved
                    : ($chatName !== '' ? $chatName : ($saved !== '' ? $saved : ($push !== '' ? $push : $phone)));

                return [
                    'id' => $c->id,
                    'whatsapp_id' => $c->whatsapp_id,
                    'phone_number' => $c->phone_number,
                    'name' => $c->name,
                    'push_name' => $c->push_name,
                    'profile_picture_url' => $c->profile_picture_url,
                    'display_name' => $displayName,
                ];
            })
            ->values();

        $sessions = $this->sessionsForUser($request->user())
            ->orderBy('display_name')
            ->get(['whatsapp_sessions.id', 'session_name', 'display_name', 'phone_number', 'status']);

        return response()->json([
            'contacts' => $contacts,
            'sessions' => $sessions,
        ]);
    }

    public function start(StartChatRequest $request): RedirectResponse
    {
        $user = $request->user();
        $session = $request->resolvedSession();

        abort_unless($user->can('use', $session), 403, 'Этот номер WhatsApp вам не назначен.');

        $contact = $request->filled('contact_id')
            ? Contact::findOrFail((int) $request->input('contact_id'))
            : $this->chatService->findOrCreateContactByPhone(
                (string) $request->input('phone'),
                $request->input('name'),
            );

        $chat = $this->chatService->findOrCreateChatForContact($contact, $session);

        if ($chat->is_archived) {
            $chat->update(['is_archived' => false]);
        }

        if (! $user->hasRole('administrator')) {
            ChatAssignment::firstOrCreate(
                ['chat_id' => $chat->id, 'user_id' => $user->id],
                ['assigned_by' => $user->id],
            );
        }

        return redirect(ChatUrl::show($chat));
    }

    public function createGroup(CreateGroupRequest $request): JsonResponse
    {
        $user = $request->user();
        $session = WhatsappSession::findOrFail((int) $request->input('whatsapp_session_id'));

        abort_unless($user->can('use', $session), 403, 'Этот номер WhatsApp вам не назначен.');

        $participants = Contact::whereIn('id', $request->input('contact_ids'))
            ->get()
            ->map(function (Contact $c): ?string {
                $raw = (string) ($c->whatsapp_id ?: $c->phone_number ?: '');
                if ($raw === '') {
                    return null;
                }

                return str_contains($raw, '@')
                    ? $raw
                    : preg_replace('/\D/', '', $raw).'@c.us';
            })
            ->filter()
            ->values()
            ->all();

        if (empty($participants)) {
            return response()->json(['success' => false, 'error' => 'Нет корректных участников.'], 422);
        }

        $result = $this->whatsappService->createGroup(
            $session->session_name,
            (string) $request->input('subject'),
            $participants,
        );

        if (empty($result['success']) || empty($result['chatId'])) {
            return response()->json([
                'success' => false,
                'error' => $result['error'] ?? 'Не удалось создать группу.',
            ], 502);
        }

        $chat = Chat::firstOrCreate(
            ['whatsapp_chat_id' => $result['chatId'], 'whatsapp_session_id' => $session->id],
            [
                'chat_name' => $request->input('subject'),
                'is_group' => true,
                'community_id' => $request->input('community_id'),
                'last_message_at' => now(),
            ],
        );

        $communityId = $request->input('community_id');
        if ($communityId && $chat->community_id !== (int) $communityId) {
            $chat->update(['community_id' => (int) $communityId]);
        }

        if (! $user->hasRole('administrator')) {
            ChatAssignment::firstOrCreate(
                ['chat_id' => $chat->id, 'user_id' => $user->id],
                ['assigned_by' => $user->id],
            );
        }

        return response()->json(['success' => true, 'chat_id' => $chat->id]);
    }

    public function syncGroups(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            abort(401);
        }

        $sessions = $this->sessionsForUser($user)
            ->where('is_active', true)
            ->get(['id', 'session_name', 'status']);

        $created = 0;
        $updated = 0;
        $errors = [];

        foreach ($sessions as $session) {
            // Синхронизируем только для реально подключённых номеров.
            if (($session->status ?? null) !== 'connected') {
                continue;
            }

            $resp = $this->whatsappService->getChats($session->session_name);
            if (empty($resp['success'])) {
                $errors[] = [
                    'session' => $session->session_name,
                    'error' => $resp['error'] ?? 'Unknown error',
                ];

                continue;
            }

            $chats = is_array($resp['chats'] ?? null) ? $resp['chats'] : [];
            foreach ($chats as $c) {
                if (! is_array($c)) {
                    continue;
                }
                if (empty($c['id']) || empty($c['isGroup'])) {
                    continue;
                }

                $waId = (string) $c['id'];
                $name = trim((string) ($c['name'] ?? '')) ?: $waId;

                // Обновляем только уже существующие группы. Новые появятся при первом сообщении (webhook).
                $chat = Chat::query()
                    ->where('whatsapp_chat_id', $waId)
                    ->where('whatsapp_session_id', $session->id)
                    ->first();

                if ($chat === null) {
                    continue;
                }

                if (($chat->chat_name ?? '') === '' || $chat->chat_name === $chat->whatsapp_chat_id) {
                    $chat->update(['chat_name' => $name, 'is_group' => true]);
                    $updated++;
                } elseif (! $chat->is_group) {
                    $chat->update(['is_group' => true]);
                    $updated++;
                }
            }
        }

        return response()->json([
            'success' => true,
            'created' => $created,
            'updated' => $updated,
            'errors' => $errors,
        ]);
    }

    public function groupParticipants(Request $request, Chat $chat): JsonResponse
    {
        $this->authorize('view', $chat);

        if (! $chat->is_group) {
            return response()->json(['success' => false, 'error' => 'Not a group chat'], 422);
        }

        $chat->load('whatsappSession:id,session_name,status');
        $session = $chat->whatsappSession;
        if (! $session || ($session->status ?? null) !== 'connected') {
            return response()->json(['success' => false, 'error' => 'WhatsApp session not connected'], 409);
        }

        $resp = $this->whatsappService->getGroupParticipants($session->session_name, (string) $chat->whatsapp_chat_id);
        if (empty($resp['success'])) {
            return response()->json([
                'success' => false,
                'error' => $resp['error'] ?? 'Failed to fetch participants',
            ], 502);
        }

        $participants = is_array($resp['participants'] ?? null) ? $resp['participants'] : [];

        // Map participants to our saved contacts (by phone digits or whatsapp_id).
        $digits = [];
        foreach ($participants as $p) {
            if (! is_array($p)) {
                continue;
            }
            $raw = (string) ($p['number'] ?? '');
            $d = preg_replace('/\D/', '', $raw) ?: null;
            if ($d) {
                $digits[] = $d;
            }
            $id = (string) ($p['id'] ?? '');
            $idDigits = preg_replace('/\D/', '', $id) ?: null;
            if ($idDigits) {
                $digits[] = $idDigits;
            }
        }
        $digits = array_values(array_unique(array_filter($digits)));

        $contacts = $digits
            ? Contact::query()
                ->whereIn('phone_number', $digits)
                ->orWhereIn('whatsapp_id', $digits)
                ->orWhereIn('whatsapp_id', array_map(fn ($d) => "{$d}@c.us", $digits))
                ->get(['id', 'phone_number', 'whatsapp_id', 'name'])
            : collect();

        $byPhone = $contacts->keyBy('phone_number');
        $byWa = $contacts->keyBy('whatsapp_id');

        $mapped = array_map(function ($p) use ($byPhone, $byWa) {
            if (! is_array($p)) {
                return $p;
            }
            $rawNumber = (string) ($p['number'] ?? '');
            $d = preg_replace('/\D/', '', $rawNumber) ?: null;
            $waId = (string) ($p['id'] ?? '');
            $contact = null;
            if ($d && $byPhone->has($d)) {
                $contact = $byPhone->get($d);
            }
            if (! $contact && $waId !== '' && $byWa->has($waId)) {
                $contact = $byWa->get($waId);
            }
            if (! $contact && $d && $byWa->has("{$d}@c.us")) {
                $contact = $byWa->get("{$d}@c.us");
            }

            if ($contact && $contact->name) {
                $p['saved_name'] = $contact->name;
            }

            return $p;
        }, $participants);

        return response()->json([
            'success' => true,
            'participants' => $mapped,
        ]);
    }

    public function timeline(Request $request, Chat $chat): JsonResponse
    {
        $this->authorize('view', $chat);

        $limit = (int) $request->input('limit', 50);
        $limit = min(100, max(1, $limit));

        $beforeTs = $request->input('before_timestamp');
        $beforeId = (int) $request->input('before_id', 0);

        $messages = $chat->messages()
            ->operatorVisible()
            ->with(OutboundChatMessageDispatcher::messageWithRelations())
            ->when(
                is_string($beforeTs) ? trim($beforeTs) !== '' : $beforeTs !== null && $beforeTs !== '',
                function ($q) use ($beforeTs, $beforeId): void {
                    if ($beforeId > 0) {
                        $q->whereRaw(
                            '(COALESCE(message_timestamp, created_at) < ?) OR (COALESCE(message_timestamp, created_at) = ? AND id < ?)',
                            [$beforeTs, $beforeTs, $beforeId],
                        );

                        return;
                    }
                    $q->whereRaw('COALESCE(message_timestamp, created_at) < ?', [$beforeTs]);
                },
            )
            ->orderByRaw('COALESCE(message_timestamp, created_at) DESC')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->reverse()
            ->values();

        return response()->json(['messages' => $messages]);
    }

    public function mediaLinksDocuments(Chat $chat): JsonResponse
    {
        $this->authorize('view', $chat);

        $messages = $chat->messages()
            ->operatorVisible()
            ->with('media')
            ->where(function ($query): void {
                $query->whereHas('media')
                    ->orWhere('body', 'regexp', 'https?://|www\\.');
            })
            ->orderByRaw('COALESCE(message_timestamp, created_at) DESC')
            ->orderByDesc('id')
            ->get(['id', 'chat_id', 'direction', 'type', 'body', 'sender_name', 'message_timestamp', 'created_at']);

        $media = [];
        $documents = [];
        $links = [];

        foreach ($messages as $message) {
            $messageTime = optional($message->message_timestamp ?: $message->created_at)->toIso8601String();

            foreach ($message->media as $item) {
                $mime = strtolower((string) $item->mime_type);
                $row = [
                    'id' => $item->id,
                    'message_id' => $message->id,
                    'mime_type' => $item->mime_type,
                    'filename' => $item->filename,
                    'file_size' => $item->file_size,
                    'url' => route('media.show', $item->id),
                    'download_url' => route('media.show', ['media' => $item->id, 'download' => 1]),
                    'message_at' => $messageTime,
                    'direction' => $message->direction,
                ];

                if (str_starts_with($mime, 'image/') || str_starts_with($mime, 'video/')) {
                    $media[] = $row;

                    continue;
                }

                $documents[] = $row;
            }

            foreach ($this->extractLinks((string) $message->body) as $url) {
                $links[] = [
                    'id' => $message->id.'-'.md5($url),
                    'message_id' => $message->id,
                    'url' => $url,
                    'host' => parse_url($url, PHP_URL_HOST) ?: $url,
                    'title' => $url,
                    'message_at' => $messageTime,
                    'direction' => $message->direction,
                ];
            }
        }

        return response()->json([
            'media' => $media,
            'links' => $links,
            'documents' => $documents,
            'counts' => [
                'media' => count($media),
                'links' => count($links),
                'documents' => count($documents),
                'total' => count($media) + count($links) + count($documents),
            ],
        ]);
    }

    /**
     * @return list<string>
     */
    private function extractLinks(string $text): array
    {
        if ($text === '') {
            return [];
        }

        preg_match_all('~\\b(?:https?://|www\\.)[^\\s<>()]+~iu', $text, $matches);
        $urls = [];

        foreach ($matches[0] ?? [] as $raw) {
            $url = rtrim((string) $raw, ".,!?;:)]}\n\r\t");
            if (str_starts_with($url, 'www.')) {
                $url = 'https://'.$url;
            }
            if ($url !== '' && ! in_array($url, $urls, true)) {
                $urls[] = $url;
            }
        }

        return $urls;
    }

    public function uploadFile(UploadFileRequest $request, Chat $chat): JsonResponse
    {
        $file = $request->file('file');
        $chat->load('whatsappSession');
        $session = $chat->whatsappSession;

        $mime = $file->getMimeType() ?? 'application/octet-stream';
        $originalName = (string) $file->getClientOriginalName();
        if (str_ends_with(strtolower($originalName), '.webm') && ! str_contains(strtolower($mime), 'webm')) {
            $mime = 'audio/webm';
        }
        $uploadHint = (string) $request->input('type', '');
        if (in_array($uploadHint, ['voice', 'ptt'], true) && str_starts_with(strtolower($mime), 'video/')) {
            $mime = 'audio/webm';
        }
        $caption = (string) $request->input('caption', '');
        $type = MediaType::detect($mime, $request->input('type'));

        $storedPath = $file->store('whatsapp-media/'.date('Y/m'), 'local');

        // Подпись оператора идёт как caption к медиа. Для медиа без подписи
        // подпись всё равно отправляется, чтобы клиент понимал, от кого файл.
        $signedCaption = OperatorSignature::prepend($request->user(), $caption);

        $message = $this->chatService->storeOutboundMessage(
            $chat,
            $session,
            $request->user(),
            $signedCaption,
        );

        $message->forceFill(['type' => $type])->save();

        MessageMedia::create([
            'message_id' => $message->id,
            'mime_type' => $mime,
            'filename' => $originalName,
            'disk_path' => $storedPath,
            'file_size' => $file->getSize() ?: 0,
        ]);

        $chat->update(['last_message_text' => MediaType::previewText($type, $signedCaption)]);

        $message->load(OutboundChatMessageDispatcher::messageWithRelations());
        broadcast(new NewMessageReceived($message, $chat->id));

        SendOutboundMessageJob::dispatch(
            $message->id,
            'media',
            [
                'disk' => 'local',
                'path' => $storedPath,
                'mimetype' => $mime,
                'filename' => $originalName,
                'caption' => $type === 'voice'
                    ? null
                    : ($signedCaption !== '' ? $signedCaption : null),
            ],
        );

        return response()->json(['success' => true, 'message' => $message]);
    }

    public function sendPoll(SendPollRequest $request, Chat $chat): JsonResponse
    {
        $question = trim((string) $request->input('question'));
        $options = array_values(array_filter(
            array_map(fn ($o) => trim((string) $o), (array) $request->input('options', [])),
            fn ($o) => $o !== '',
        ));

        if (count($options) < 2) {
            return response()->json(['success' => false, 'error' => 'Добавьте хотя бы два варианта ответа.'], 422);
        }

        $allowMultiple = $request->boolean('allow_multiple_answers');
        $chat->load('whatsappSession');
        $session = $chat->whatsappSession;

        $message = $this->chatService->storeOutboundMessage(
            $chat,
            $session,
            $request->user(),
            $question,
        );

        $message->forceFill([
            'type' => 'poll',
            'metadata' => [
                'poll' => [
                    'question' => $question,
                    'options' => $options,
                    'allow_multiple_answers' => $allowMultiple,
                ],
            ],
        ])->save();

        $chat->update(['last_message_text' => '📊 '.$question]);

        $message->load(OutboundChatMessageDispatcher::messageWithRelations());
        broadcast(new NewMessageReceived($message, $chat->id));

        SendOutboundMessageJob::dispatch(
            $message->id,
            'poll',
            [
                'question' => $question,
                'options' => $options,
                'allow_multiple' => $allowMultiple,
            ],
        );

        return response()->json(['success' => true, 'message' => $message]);
    }

    public function sendContact(SendContactRequest $request, Chat $chat): JsonResponse
    {
        $phone = (string) $request->input('phone');
        $phoneDigits = preg_replace('/\D/', '', $phone) ?: $phone;
        $displayName = trim((string) $request->input('name')) ?: $phoneDigits;

        $chat->load('whatsappSession');
        $session = $chat->whatsappSession;

        $vcard = VCard::build($displayName, $phoneDigits, $request->input('email'), $request->input('company'));

        $message = $this->chatService->storeOutboundMessage(
            $chat,
            $session,
            $request->user(),
            $displayName,
        );

        $message->forceFill([
            'type' => 'contact',
            'metadata' => [
                'contact' => [
                    'id' => $request->input('contact_id'),
                    'name' => $displayName,
                    'phone' => $phoneDigits,
                    'email' => $request->input('email'),
                    'company' => $request->input('company'),
                    'avatar_url' => $request->input('avatar_url'),
                    'vcard' => $vcard,
                ],
            ],
        ])->save();

        $chat->update(['last_message_text' => '👤 '.$displayName]);

        $message->load(OutboundChatMessageDispatcher::messageWithRelations());
        broadcast(new NewMessageReceived($message, $chat->id));

        SendOutboundMessageJob::dispatch(
            $message->id,
            'contact',
            ['vcard' => $vcard, 'display_name' => $displayName],
        );

        return response()->json(['success' => true, 'message' => $message]);
    }

    private function assignableUsersFor(?User $user, ?Chat $chat = null): Collection
    {
        if (! $user) {
            return collect();
        }

        $query = User::query()
            ->where('is_active', true)
            ->with(['roles:id,name', 'department:id,name', 'departments:id,name'])
            ->orderBy('name');

        /** @var list<int>|null Отделы чата для сортировки списка у администратора (сверху — из отделов чата). */
        $adminChatDepartmentIds = null;

        if ($user->hasRole('administrator')) {
            if ($chat === null) {
                return collect();
            }
            $rawDeptIds = $chat->relationLoaded('departments')
                ? $chat->departments->pluck('id')->all()
                : $chat->departments()->pluck('departments.id')->all();
            $adminChatDepartmentIds = array_values(array_map(intval(...), $rawDeptIds));
        } elseif ($user->hasRole('manager')) {
            $managerDeptIds = $user->departmentIds();
            if ($managerDeptIds === []) {
                return collect();
            }
            $query->whereHas('departments', static fn ($q) => $q->whereIn('departments.id', $managerDeptIds));
        } else {
            return collect();
        }

        $users = $query->get(['id', 'name', 'email', 'department_id']);

        if ($adminChatDepartmentIds !== null) {
            $users = $users->sortBy(function (User $u) use ($adminChatDepartmentIds): array {
                $userDeptIds = $u->departments->pluck('id')->map(fn ($v) => (int) $v)->all();
                $inChatDept = array_intersect($userDeptIds, $adminChatDepartmentIds) !== [];

                return [$inChatDept ? 0 : 1, mb_strtolower($u->name)];
            })->values();
        }

        return $users
            ->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'department_id' => $u->department_id,
                'department_name' => $u->department?->name,
                'department_ids' => $u->departments->pluck('id')->map(fn ($v) => (int) $v)->all(),
                'department_names' => $u->departments->pluck('name')->all(),
                'roles' => $u->roles->pluck('name')->all(),
            ])
            ->values();
    }

    private function sessionsForUser(?User $user)
    {
        $query = WhatsappSession::where('is_active', true);

        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->hasRole('administrator')) {
            return $query;
        }

        return $query->whereIn(
            'whatsapp_sessions.id',
            $user->whatsappSessions()->pluck('whatsapp_sessions.id'),
        );
    }

    /**
     * Лёгкий сайдбар для Chats/Show: только текущий чат + метаданные пагинации.
     * Полный список подгружается на клиенте через {@see self::feed()}.
     *
     * @return array{data: list<Chat>, current_page: int, last_page: int, per_page: int, total: int}
     */
    private function sidebarChatsForShow(
        Request $request,
        Chat $chat,
        string $listOwnership,
        ?string $listFilter,
    ): array {
        $query = $this->sidebarChatsQuery($request->user(), $chat, $listOwnership, $listFilter);

        $total = (clone $query)->count();
        $perPage = self::CHAT_LIST_PER_PAGE;
        $lastPage = max(1, (int) ceil($total / $perPage));

        $data = [];
        $current = (clone $query)->whereKey($chat->id)->first();
        if ($current instanceof Chat) {
            $this->chatService->enrichAttentionMeta([$current]);
            $data = [$current];
        }

        return [
            'data' => $data,
            'current_page' => 1,
            'last_page' => $lastPage,
            'per_page' => $perPage,
            'total' => $total,
        ];
    }

    /**
     * @return Builder<Chat>
     */
    private function sidebarChatsQuery(User $user, Chat $contextChat, string $listOwnership, ?string $listFilter): Builder
    {
        return $this->chatService->getChatsForUser($user, null, $listOwnership, $listFilter)
            ->where(function (Builder $q) use ($contextChat): void {
                $q->where('is_archived', false);
                if ($contextChat->is_archived) {
                    $q->orWhere('id', $contextChat->id);
                }
            });
    }
}
