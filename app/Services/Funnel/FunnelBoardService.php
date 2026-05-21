<?php

declare(strict_types=1);

namespace App\Services\Funnel;

use App\Models\Chat;
use App\Models\ChatFunnelTransition;
use App\Models\Department;
use App\Models\Funnel;
use App\Models\User;
use App\Models\WhatsappSession;
use App\Services\ChatService;
use App\Support\FunnelBoardFilters;
use App\Support\FunnelStageType;
use App\Support\PhoneFormatter;
use App\Support\TenantCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class FunnelBoardService
{
    public const INBOX_STAGE_ID = -1;

    public const ORPHAN_STAGE_ID = 0;

    public const CARDS_PER_STAGE = 50;

    public function __construct(
        private readonly ChatService $chatService,
    ) {}

    /**
     * @return Collection<int, Funnel>
     */
    public function activeFunnels(): Collection
    {
        return Funnel::query()
            ->where('company_id', TenantCompany::id())
            ->where('is_active', true)
            ->orderBy('position')
            ->orderBy('id')
            ->get(['id', 'name', 'color', 'description']);
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    public function filterDepartments(User $user): array
    {
        $query = Department::query()
            ->where('is_active', true)
            ->orderBy('name');

        if (! $user->hasRole('administrator')) {
            $deptIds = $user->departmentIds();
            if ($deptIds === []) {
                return [];
            }
            $query->whereIn('id', $deptIds);
        }

        return $query
            ->get(['id', 'name'])
            ->map(static fn (Department $d): array => ['id' => (int) $d->id, 'name' => (string) $d->name])
            ->values()
            ->all();
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    public function filterAssignees(User $user): array
    {
        if (! $user->hasAnyRole(['administrator', 'manager'])) {
            return [];
        }

        $query = User::query()
            ->where('company_id', TenantCompany::id())
            ->where('is_active', true)
            ->orderBy('name');

        if ($user->hasRole('manager') && ! $user->hasRole('administrator')) {
            $deptIds = $user->departmentIds();
            if ($deptIds === []) {
                return [];
            }
            $query->whereHas('departments', static fn (Builder $q) => $q->whereIn('departments.id', $deptIds));
        }

        return $query
            ->get(['id', 'name'])
            ->map(static fn (User $u): array => ['id' => (int) $u->id, 'name' => (string) $u->name])
            ->values()
            ->all();
    }

    /**
     * @return list<array{id: int, label: string}>
     */
    public function filterWhatsappSessions(User $user): array
    {
        if (! $user->hasAnyRole(['administrator', 'manager'])) {
            return [];
        }

        $query = WhatsappSession::query();

        if ($user->hasRole('manager') && ! $user->hasRole('administrator')) {
            $sessionIds = $user->whatsappSessions()->pluck('whatsapp_sessions.id');
            if ($sessionIds->isEmpty()) {
                return [];
            }
            $query->whereIn('id', $sessionIds);
        }

        return $query
            ->orderBy('display_name')
            ->orderBy('id')
            ->get(['id', 'display_name', 'phone_number', 'session_name'])
            ->map(static function (WhatsappSession $session): array {
                $label = trim((string) ($session->display_name ?: $session->phone_number ?: $session->session_name ?: ''));

                return [
                    'id' => (int) $session->id,
                    'label' => $label !== '' ? $label : 'Сессия #'.$session->id,
                ];
            })
            ->values()
            ->all();
    }

    public function board(User $user, int $funnelId, FunnelBoardFilters $filters): array
    {
        $funnel = Funnel::query()
            ->where('company_id', TenantCompany::id())
            ->where('is_active', true)
            ->with([
                'stages' => static fn ($q) => $q
                    ->where('is_active', true)
                    ->orderBy('position')
                    ->orderBy('id'),
            ])
            ->findOrFail($funnelId);

        $chats = $this->boardChatsQuery($user, $funnel->id, $filters)->get();
        $inboxChats = $this->inboxChatsQuery($user, $filters)->get();
        $stageStats = $this->computeStageStats($funnel, $chats, $inboxChats);

        return $this->buildBoardPayload($funnel, $chats, $inboxChats, $stageStats);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function stageCards(User $user, int $funnelId, int $stageId, FunnelBoardFilters $filters, int $offset): array
    {
        if ($stageId === self::INBOX_STAGE_ID) {
            $query = $this->inboxChatsQuery($user, $filters);
        } else {
            $query = $this->boardChatsQuery($user, $funnelId, $filters);
            if ($stageId === self::ORPHAN_STAGE_ID) {
                $query->whereNull('funnel_stage_id');
            } else {
                $query->where('funnel_stage_id', $stageId);
            }
        }

        return $query
            ->skip(max(0, $offset))
            ->take(self::CARDS_PER_STAGE)
            ->get()
            ->map(fn (Chat $chat): array => $this->serializeCard($chat))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function cardForBroadcast(User $user, Chat $chat, FunnelBoardFilters $filters): ?array
    {
        if ($chat->funnel_id === null || $chat->is_group || $chat->is_archived) {
            return null;
        }

        $visible = $this->boardChatsQuery($user, (int) $chat->funnel_id, $filters)
            ->whereKey($chat->id)
            ->exists();

        if (! $visible) {
            return null;
        }

        $chat->loadMissing([
            'contact:id,name,push_name,phone_number,profile_picture_url',
            'assignments.user:id,name',
        ]);

        return $this->serializeCard($chat);
    }

    /**
     * @param  Builder<Chat>  $query
     * @return Builder<Chat>
     */
    public function boardChatsQuery(User $user, int $funnelId, FunnelBoardFilters $filters): Builder
    {
        $query = $this->chatService->queryVisibleToUser($user)
            ->where('funnel_id', $funnelId)
            ->where('is_group', false)
            ->where('is_archived', false)
            ->with([
                'contact:id,name,push_name,phone_number,profile_picture_url',
                'assignments.user:id,name',
            ]);

        $query = $this->applyScope($query, $user, $filters->scope);
        $query = $this->applyExtraFilters($query, $user, $filters);

        return $query
            ->orderByDesc('last_message_at')
            ->orderByDesc('id');
    }

    /**
     * @param  Builder<Chat>  $query
     * @return Builder<Chat>
     */
    public function inboxChatsQuery(User $user, FunnelBoardFilters $filters): Builder
    {
        $query = $this->chatService->queryVisibleToUser($user)
            ->whereNull('funnel_id')
            ->where('is_group', false)
            ->where('is_archived', false)
            ->with([
                'contact:id,name,push_name,phone_number,profile_picture_url',
                'assignments.user:id,name',
            ]);

        $query = $this->applyScope($query, $user, $filters->scope);
        $query = $this->applyExtraFilters($query, $user, $filters);

        return $query
            ->orderByDesc('last_message_at')
            ->orderByDesc('id');
    }

    /**
     * @param  Collection<int, Chat>  $funnelChats
     * @param  Collection<int, Chat>  $inboxChats
     * @param  array<int, array{cards_total: int, entered_7d: int, conversion_pct: float|null, avg_days: float|null}>  $stageStats
     * @return array{
     *     funnel: array{id: int, name: string, color: string, description: string|null},
     *     stages: list<array<string, mixed>>
     * }
     */
    private function buildBoardPayload(
        Funnel $funnel,
        Collection $funnelChats,
        Collection $inboxChats,
        array $stageStats,
    ): array {
        $cardsByStage = [];
        foreach ($funnelChats as $chat) {
            $stageId = (int) ($chat->funnel_stage_id ?? self::ORPHAN_STAGE_ID);
            $cardsByStage[$stageId] ??= collect();
            $cardsByStage[$stageId]->push($chat);
        }

        $inboxCollection = $inboxChats->values();

        $stages = [];

        $stages[] = $this->serializeStageColumn(
            id: self::INBOX_STAGE_ID,
            name: 'Входящие',
            color: '#64748b',
            stageType: FunnelStageType::OTHER,
            stageTone: 'neutral',
            position: -2,
            chats: $inboxCollection,
            stats: $stageStats[self::INBOX_STAGE_ID] ?? self::emptyStageStats($inboxCollection->count()),
            isInbox: true,
        );

        foreach ($funnel->stages as $stage) {
            $stageId = (int) $stage->id;
            $type = FunnelStageType::normalize($stage->stage_type);
            $chatCollection = ($cardsByStage[$stageId] ?? collect())->values();

            $stages[] = $this->serializeStageColumn(
                id: $stageId,
                name: (string) $stage->name,
                color: (string) ($stage->color ?: '#01b964'),
                stageType: $type,
                stageTone: self::stageTone($type, (string) $stage->name),
                position: (int) $stage->position,
                chats: $chatCollection,
                stats: $stageStats[$stageId] ?? self::emptyStageStats($chatCollection->count()),
                isInbox: false,
                wipLimit: $stage->wip_limit !== null ? (int) $stage->wip_limit : null,
            );
        }

        $orphanCollection = ($cardsByStage[self::ORPHAN_STAGE_ID] ?? collect())->values();
        if ($orphanCollection->isNotEmpty()) {
            $stages[] = $this->serializeStageColumn(
                id: self::ORPHAN_STAGE_ID,
                name: 'Без этапа',
                color: '#94a3b8',
                stageType: FunnelStageType::OTHER,
                stageTone: 'neutral',
                position: -1,
                chats: $orphanCollection,
                stats: $stageStats[self::ORPHAN_STAGE_ID] ?? self::emptyStageStats($orphanCollection->count()),
                isInbox: false,
            );
        }

        return [
            'funnel' => [
                'id' => (int) $funnel->id,
                'name' => (string) $funnel->name,
                'color' => (string) ($funnel->color ?: '#01b964'),
                'description' => $funnel->description,
            ],
            'stages' => $stages,
        ];
    }

    /**
     * @param  Collection<int, Chat>  $chats
     * @param  array{cards_total: int, entered_7d: int, conversion_pct: float|null, avg_days: float|null}  $stats
     * @return array<string, mixed>
     */
    private function serializeStageColumn(
        int $id,
        string $name,
        string $color,
        string $stageType,
        string $stageTone,
        int $position,
        Collection $chats,
        array $stats,
        bool $isInbox,
        ?int $wipLimit = null,
    ): array {
        $total = $stats['cards_total'] > 0 ? $stats['cards_total'] : $chats->count();
        $limited = $chats->take(self::CARDS_PER_STAGE);

        return [
            'id' => $id,
            'name' => $name,
            'color' => $color,
            'stage_type' => $stageType,
            'stage_tone' => $stageTone,
            'position' => $position,
            'is_inbox' => $isInbox,
            'wip_limit' => $wipLimit,
            'cards' => $limited->map(fn (Chat $chat): array => $this->serializeCard($chat))->values()->all(),
            'cards_total' => $total,
            'has_more' => $total > self::CARDS_PER_STAGE,
            'stats' => $stats,
        ];
    }

    /**
     * @param  Collection<int, Chat>  $funnelChats
     * @param  Collection<int, Chat>  $inboxChats
     * @return array<int, array{cards_total: int, entered_7d: int, conversion_pct: float|null, avg_days: float|null, sparkline: list<int>}>
     */
    private function computeStageStats(Funnel $funnel, Collection $funnelChats, Collection $inboxChats): array
    {
        $since = now()->subDays(7);
        $funnelId = (int) $funnel->id;

        $countsByStage = [];
        foreach ($funnelChats->groupBy(fn (Chat $chat): int => (int) ($chat->funnel_stage_id ?? self::ORPHAN_STAGE_ID)) as $stageId => $group) {
            $countsByStage[(int) $stageId] = $group->count();
        }

        $countsByStage[self::INBOX_STAGE_ID] = $inboxChats->count();

        $stageIds = $funnel->stages->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $sparklines = $this->sparklinesByStage($funnelId, $stageIds);
        $orderedStages = $funnel->stages->values();
        $nextStageById = [];
        foreach ($orderedStages as $index => $stage) {
            $next = $orderedStages[$index + 1] ?? null;
            $nextStageById[(int) $stage->id] = $next !== null ? (int) $next->id : null;
        }

        $entriesByStage = ChatFunnelTransition::query()
            ->where('to_funnel_id', $funnelId)
            ->whereIn('to_stage_id', $stageIds)
            ->where('created_at', '>=', $since)
            ->selectRaw('to_stage_id, count(*) as cnt')
            ->groupBy('to_stage_id')
            ->pluck('cnt', 'to_stage_id')
            ->map(fn ($cnt): int => (int) $cnt)
            ->all();

        $stats = [];
        foreach ($stageIds as $stageId) {
            $entries = (int) ($entriesByStage[$stageId] ?? 0);
            $nextStageId = $nextStageById[$stageId] ?? null;
            $forward = 0;
            if ($nextStageId !== null) {
                $forward = (int) ChatFunnelTransition::query()
                    ->where('from_funnel_id', $funnelId)
                    ->where('from_stage_id', $stageId)
                    ->where('to_stage_id', $nextStageId)
                    ->where('created_at', '>=', $since)
                    ->count();
            }

            $stats[$stageId] = [
                'cards_total' => (int) ($countsByStage[$stageId] ?? 0),
                'entered_7d' => $entries,
                'conversion_pct' => $entries > 0 && $nextStageId !== null
                    ? round($forward / $entries * 100, 1)
                    : null,
                'avg_days' => $this->avgDaysOnStage($funnelId, $stageId, $since),
                'sparkline' => $sparklines[$stageId] ?? array_fill(0, 7, 0),
            ];
        }

        if (isset($countsByStage[self::ORPHAN_STAGE_ID])) {
            $stats[self::ORPHAN_STAGE_ID] = self::emptyStageStats($countsByStage[self::ORPHAN_STAGE_ID]);
        }

        $stats[self::INBOX_STAGE_ID] = self::emptyStageStats($countsByStage[self::INBOX_STAGE_ID] ?? 0);

        return $stats;
    }

    private function avgDaysOnStage(int $funnelId, int $stageId, \DateTimeInterface $since): ?float
    {
        $rows = ChatFunnelTransition::query()
            ->where('from_funnel_id', $funnelId)
            ->where('from_stage_id', $stageId)
            ->where('created_at', '>=', $since)
            ->whereNotNull('to_stage_id')
            ->orderBy('chat_id')
            ->orderBy('created_at')
            ->get(['chat_id', 'created_at']);

        if ($rows->isEmpty()) {
            return null;
        }

        $durations = [];

        foreach ($rows as $row) {
            $chatId = (int) $row->chat_id;
            $enter = ChatFunnelTransition::query()
                ->where('chat_id', $chatId)
                ->where('to_funnel_id', $funnelId)
                ->where('to_stage_id', $stageId)
                ->where('created_at', '<=', $row->created_at)
                ->orderByDesc('created_at')
                ->value('created_at');

            if ($enter === null) {
                continue;
            }

            $hours = $enter->diffInMinutes($row->created_at) / 60;
            if ($hours > 0) {
                $durations[] = $hours / 24;
            }
        }

        if ($durations === []) {
            return null;
        }

        return round(array_sum($durations) / count($durations), 1);
    }

    /**
     * @param  list<int>  $stageIds
     * @return array<int, list<int>>
     */
    private function sparklinesByStage(int $funnelId, array $stageIds): array
    {
        if ($stageIds === []) {
            return [];
        }

        $start = now()->subDays(6)->startOfDay();
        $dayKeys = [];
        for ($i = 0; $i < 7; $i++) {
            $dayKeys[] = $start->copy()->addDays($i)->toDateString();
        }

        $result = [];
        foreach ($stageIds as $stageId) {
            $result[$stageId] = array_fill(0, 7, 0);
        }

        ChatFunnelTransition::query()
            ->where('to_funnel_id', $funnelId)
            ->whereIn('to_stage_id', $stageIds)
            ->where('created_at', '>=', $start)
            ->get(['to_stage_id', 'created_at'])
            ->each(function (ChatFunnelTransition $row) use (&$result, $dayKeys): void {
                $stageId = (int) $row->to_stage_id;
                $day = $row->created_at?->toDateString();
                if ($day === null || ! isset($result[$stageId])) {
                    return;
                }
                $index = array_search($day, $dayKeys, true);
                if ($index !== false) {
                    $result[$stageId][$index]++;
                }
            });

        return $result;
    }

    /**
     * @return array{cards_total: int, entered_7d: int, conversion_pct: float|null, avg_days: float|null, sparkline: list<int>}
     */
    private static function emptyStageStats(int $cardsTotal): array
    {
        return [
            'cards_total' => $cardsTotal,
            'entered_7d' => 0,
            'conversion_pct' => null,
            'avg_days' => null,
            'sparkline' => array_fill(0, 7, 0),
        ];
    }

    /**
     * @param  Builder<Chat>  $query
     * @return Builder<Chat>
     */
    private function applyScope(Builder $query, User $user, string $scope): Builder
    {
        if ($scope === 'mine') {
            return $query->whereHas('assignments', static fn (Builder $q) => $q->where('user_id', $user->id));
        }

        if ($scope !== 'department') {
            return $query;
        }

        $deptIds = $user->departmentIds();
        if ($deptIds === []) {
            return $query->whereRaw('0 = 1');
        }

        $deptUserIds = User::query()
            ->whereHas('departments', static fn (Builder $q) => $q->whereIn('departments.id', $deptIds))
            ->pluck('id');

        return $query->where(function (Builder $q) use ($deptIds, $deptUserIds): void {
            $q->whereHas('departments', static fn (Builder $dq) => $dq->whereIn('departments.id', $deptIds));
            if ($deptUserIds->isNotEmpty()) {
                $q->orWhereHas('assignments', static fn (Builder $aq) => $aq->whereIn('user_id', $deptUserIds));
            }
        });
    }

    /**
     * @param  Builder<Chat>  $query
     * @return Builder<Chat>
     */
    private function applyExtraFilters(Builder $query, User $user, FunnelBoardFilters $filters): Builder
    {
        if ($filters->assigneeId !== null) {
            $query->whereHas('assignments', static fn (Builder $q) => $q->where('user_id', $filters->assigneeId));
        }

        if ($filters->departmentId !== null) {
            if (! $user->hasRole('administrator')) {
                abort_unless(in_array($filters->departmentId, $user->departmentIds(), true), 403);
            }
            $query->whereHas('departments', static fn (Builder $q) => $q->where('departments.id', $filters->departmentId));
        }

        if ($filters->whatsappSessionId !== null) {
            $query->where('whatsapp_session_id', $filters->whatsappSessionId);
        }

        if ($filters->search !== null) {
            $search = $filters->search;
            $query->where(function (Builder $q) use ($search): void {
                $q->where('chat_name', 'like', "%{$search}%")
                    ->orWhere('last_message_text', 'like', "%{$search}%")
                    ->orWhereHas('contact', static fn (Builder $cq) => $cq
                        ->where('phone_number', 'like', "%{$search}%")
                        ->orWhere('name', 'like', "%{$search}%")
                        ->orWhere('push_name', 'like', "%{$search}%"));
            });
        }

        return $query;
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeCard(Chat $chat): array
    {
        $contact = $chat->contact;
        $name = trim((string) ($contact?->name ?: $contact?->push_name ?: $chat->chat_name ?: ''));
        $phone = $contact?->phone_number
            ? (PhoneFormatter::normalize((string) $contact->phone_number) ?? (string) $contact->phone_number)
            : null;

        $assignees = $chat->assignments
            ->map(static fn ($row): ?array => $row->user
                ? ['id' => (int) $row->user->id, 'name' => (string) $row->user->name]
                : null)
            ->filter()
            ->values()
            ->all();

        return [
            'id' => (int) $chat->id,
            'name' => $name !== '' ? $name : ($phone ?? 'Без имени'),
            'phone' => $phone,
            'last_message_text' => $chat->last_message_text,
            'last_message_at' => $chat->last_message_at?->toIso8601String(),
            'unread_count' => (int) ($chat->unread_count ?? 0),
            'assignees' => $assignees,
            'funnel_stage_id' => $chat->funnel_stage_id !== null ? (int) $chat->funnel_stage_id : null,
            'funnel_stage_locked' => (bool) $chat->funnel_stage_locked,
            'funnel_ai_reason' => $chat->funnel_ai_last_reason,
        ];
    }

    private static function stageTone(string $stageType, string $stageName): string
    {
        if ($stageType === FunnelStageType::DONE) {
            return 'done';
        }

        $n = mb_strtolower(trim($stageName));
        if ($n !== '' && self::matchesAny($n, ['отказ', 'lost', 'reject', 'нецелев', 'спам', 'отмен'])) {
            return 'lost';
        }

        return 'default';
    }

    /**
     * @param  list<string>  $needles
     */
    private static function matchesAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }
}
