<?php

declare(strict_types=1);

namespace App\Services\DialogAnalytics;

use App\Models\Chat;
use App\Models\Department;
use App\Models\Message;
use App\Models\SystemSetting;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

final class DialogAnalyticsService
{
    private const STUCK_IDLE_SECONDS = 86400; // 24h without any message

    private const RANKING_LIMIT = 8;

    public function __construct(
        private int $slaSeconds,
    ) {}

    public static function fromSettings(): self
    {
        $raw = SystemSetting::getValue('analytics.sla_first_response_seconds');
        $sla = is_numeric($raw) ? (int) $raw : 300;

        return new self(max(60, $sla));
    }

    /**
     * @return array<string, mixed>
     */
    public function build(User $user, DialogAnalyticsFilters $filters): array
    {
        $chatIds = $this->filteredChatIds($user, $filters);

        if ($chatIds === []) {
            return $this->emptyPayload($filters);
        }

        $messages = $this->loadMessagesForChats($chatIds);
        $chats = Chat::query()
            ->whereIn('id', $chatIds)
            ->with(['contact', 'assignments.user', 'departments'])
            ->get()
            ->keyBy('id');

        $pairStats = $this->computeResponsePairs($messages, $user, $filters);
        $dailyBuckets = $this->bucketMessagesByDay($messages, $filters->from, $filters->to);

        $summary = $this->buildSummary(
            $chatIds,
            $chats,
            $pairStats,
            $filters,
        );

        $employeeStats = $this->buildEmployeeStats($pairStats, $chats, $chatIds, $filters);
        $employeeStats = $this->filterEmployeeStatsByDepartment($employeeStats, $filters);
        $rankings = $this->buildRankings($employeeStats);
        $departmentStats = $this->buildDepartmentStats($employeeStats, $chats, $chatIds, $pairStats, $filters);

        $chartData = $this->buildChartData(
            $dailyBuckets,
            $pairStats,
            $employeeStats,
            $chats,
            $filters,
        );

        $problematic = $this->buildProblematicChats($chats, $messages, $filters);

        return [
            'sla_seconds' => $this->slaSeconds,
            'summary' => $summary,
            'employee_stats' => $employeeStats,
            'department_stats' => $departmentStats,
            'rankings' => $rankings,
            'chart_data' => $chartData,
            'problematic_chats' => $problematic,
        ];
    }

    /**
     * @param  array<int>  $chatIds
     * @return Collection<int, Message>
     */
    private function loadMessagesForChats(array $chatIds): Collection
    {
        return Message::query()
            ->whereIn('chat_id', $chatIds)
            ->whereIn('direction', ['inbound', 'outbound'])
            ->orderBy('chat_id')
            ->orderByRaw('COALESCE(message_timestamp, created_at)')
            ->orderBy('id')
            ->get(['id', 'chat_id', 'direction', 'sent_by_user_id', 'message_timestamp', 'created_at']);
    }

    /**
     * @param  Collection<int, Message>  $messages
     * @return array{
     *   pairs_by_user: array<int, list<float|int>>,
     *   first_pairs: list<float|int>,
     *   max_wait: int,
     *   overdue_count: int,
     *   pair_count: int,
     *   max_gap_by_chat: array<int, int>,
     *   response_seconds_by_day: array<string, list<float|int>>
     * }
     */
    private function computeResponsePairs(Collection $messages, User $user, DialogAnalyticsFilters $filters): array
    {
        $pairsByUser = [];
        $firstPairs = [];
        $maxWait = 0;
        $overdueCount = 0;
        $pairCount = 0;
        $maxGapByChat = [];
        $responseByDay = [];

        $effectiveUserIds = $this->effectiveStaffUserIds($user, $filters);

        foreach ($messages->groupBy('chat_id') as $chatId => $chatMessages) {
            $lastInboundAt = null;
            $firstInboundAt = null;
            $firstStaffReplyDone = false;
            $prevTs = null;

            foreach ($chatMessages as $m) {
                $t = $this->messageTime($m);
                if ($prevTs !== null) {
                    $gap = $t->diffInSeconds($prevTs);
                    $maxGapByChat[$chatId] = max($maxGapByChat[$chatId] ?? 0, $gap);
                }
                $prevTs = $t;

                if ($m->direction === 'inbound') {
                    $lastInboundAt = $t;
                    if ($firstInboundAt === null) {
                        $firstInboundAt = $t;
                    }
                } elseif ($m->direction === 'outbound' && $m->sent_by_user_id) {
                    $uid = (int) $m->sent_by_user_id;
                    if (! $firstStaffReplyDone && $firstInboundAt !== null) {
                        $firstPairs[] = $t->diffInSeconds($firstInboundAt);
                        $firstStaffReplyDone = true;
                    }
                    $countsForUser = $effectiveUserIds === null || in_array($uid, $effectiveUserIds, true);
                    if ($lastInboundAt !== null && $countsForUser) {
                        $sec = $t->diffInSeconds($lastInboundAt);
                        $maxWait = max($maxWait, $sec);
                        $pairCount++;
                        if ($sec > $this->slaSeconds) {
                            $overdueCount++;
                        }
                        $pairsByUser[$uid] ??= [];
                        $pairsByUser[$uid][] = $sec;
                        $dayKey = $t->format('Y-m-d');
                        $responseByDay[$dayKey] ??= [];
                        $responseByDay[$dayKey][] = $sec;
                    }
                    $lastInboundAt = null;
                }
            }
        }

        return [
            'pairs_by_user' => $pairsByUser,
            'first_pairs' => $firstPairs,
            'max_wait' => $maxWait,
            'overdue_count' => $overdueCount,
            'pair_count' => $pairCount,
            'max_gap_by_chat' => $maxGapByChat,
            'response_seconds_by_day' => $responseByDay,
        ];
    }

    /**
     * @return list<int>|null null = all staff
     */
    private function effectiveStaffUserIds(User $user, DialogAnalyticsFilters $filters): ?array
    {
        if ($user->hasRole('employee')) {
            return [(int) $user->id];
        }
        if ($filters->employeeId !== null) {
            return [(int) $filters->employeeId];
        }

        return null;
    }

    private function messageTime(Message $m): Carbon
    {
        if ($m->message_timestamp instanceof Carbon) {
            return $m->message_timestamp;
        }

        return Carbon::parse($m->created_at);
    }

    /**
     * @param  Collection<int, Message>  $messages
     * @return array<string, int> date Y-m-d => count
     */
    private function bucketMessagesByDay(Collection $messages, Carbon $from, Carbon $to): array
    {
        $buckets = [];
        $cursor = $from->copy()->startOfDay();
        $end = $to->copy()->endOfDay();
        while ($cursor->lte($end)) {
            $buckets[$cursor->format('Y-m-d')] = 0;
            $cursor->addDay();
        }

        foreach ($messages as $m) {
            $t = $this->messageTime($m);
            if ($t->lt($from) || $t->gt($to)) {
                continue;
            }
            $key = $t->format('Y-m-d');
            if (! array_key_exists($key, $buckets)) {
                $buckets[$key] = 0;
            }
            $buckets[$key]++;
        }

        return $buckets;
    }

    /**
     * @param  array<int, float>  $dailyResponseSum
     * @param  array<int, int>  $dailyResponseCount
     */
    private function buildSummary(
        array $chatIds,
        Collection $chats,
        array $pairStats,
        DialogAnalyticsFilters $filters,
    ): array {
        $total = count($chatIds);
        $active = $chats->where('is_archived', false)->count();
        $closed = $chats->where('is_archived', true)->count();
        $unanswered = $chats->filter(function (Chat $c): bool {
            return ! $c->is_archived && $c->last_message_direction === 'inbound';
        })->count();

        $firstPairs = $pairStats['first_pairs'];
        $avgFirst = $firstPairs === [] ? null : round(array_sum($firstPairs) / count($firstPairs));

        $allPairs = [];
        foreach ($pairStats['pairs_by_user'] as $vals) {
            foreach ($vals as $v) {
                $allPairs[] = $v;
            }
        }
        $avgResp = $allPairs === [] ? null : round(array_sum($allPairs) / count($allPairs));

        $overduePct = $pairStats['pair_count'] > 0
            ? round(100 * $pairStats['overdue_count'] / $pairStats['pair_count'], 1)
            : null;

        $closeTimes = [];
        foreach ($chats as $c) {
            if ($c->is_archived && $c->created_at) {
                $closeTimes[] = $c->updated_at->diffInSeconds(Carbon::parse($c->created_at));
            }
        }
        $avgClose = $closeTimes === [] ? null : round(array_sum($closeTimes) / count($closeTimes));

        $idleNewChat = $this->averageIdleBeforeNewChat($chats);

        $uniqueStaff = $chats->pluck('assignments')->flatten()->pluck('user_id')->unique()->count();
        $load = $uniqueStaff > 0 ? round($total / $uniqueStaff, 2) : null;

        return [
            'total_dialogs' => $total,
            'active_dialogs' => $active,
            'closed_dialogs' => $closed,
            'avg_first_response_seconds' => $avgFirst,
            'avg_response_seconds' => $avgResp,
            'max_client_wait_seconds' => $pairStats['max_wait'] > 0 ? $pairStats['max_wait'] : null,
            'unanswered_dialogs' => $unanswered,
            'avg_idle_before_new_chat_seconds' => $idleNewChat,
            'avg_time_to_close_seconds' => $avgClose,
            'overdue_response_percent' => $overduePct,
            'dialogs_per_staff_member' => $load,
        ];
    }

    /**
     * @param  Collection<int, Chat>  $chats
     */
    private function averageIdleBeforeNewChat(Collection $chats): ?float
    {
        $byContact = $chats->filter(fn (Chat $c) => $c->contact_id !== null)->groupBy('contact_id');
        $gaps = [];
        foreach ($byContact as $group) {
            $ordered = $group->sortBy(fn (Chat $c) => $c->created_at?->timestamp ?? 0)->values();
            for ($i = 1; $i < $ordered->count(); $i++) {
                $prev = $ordered[$i - 1];
                $cur = $ordered[$i];
                $prevEnd = $prev->last_message_at ?? $prev->updated_at;
                if ($prevEnd && $cur->created_at) {
                    $gaps[] = $cur->created_at->diffInSeconds(Carbon::parse($prevEnd));
                }
            }
        }

        if ($gaps === []) {
            return null;
        }

        return round(array_sum($gaps) / count($gaps));
    }

    /**
     * @param  array<string, mixed>  $pairStats
     * @param  Collection<int, Chat>  $chats
     * @param  array<int>  $chatIds
     * @return list<array<string, mixed>>
     */
    private function buildEmployeeStats(
        array $pairStats,
        Collection $chats,
        array $chatIds,
        DialogAnalyticsFilters $filters,
    ): array {
        $userIds = collect($pairStats['pairs_by_user'])->keys()->merge(
            $chats->pluck('assignments')->flatten()->pluck('user_id'),
        )->unique()->filter()->values();

        $users = User::query()
            ->whereIn('id', $userIds)
            ->with('departments:id')
            ->get(['id', 'name', 'department_id'])
            ->keyBy('id');

        $closedByUser = [];
        foreach ($chats as $c) {
            if (! $c->is_archived) {
                continue;
            }
            foreach ($c->assignments as $as) {
                $uid = (int) $as->user_id;
                $closedByUser[$uid] = ($closedByUser[$uid] ?? 0) + 1;
            }
        }

        $dialogsByUser = [];
        foreach ($chats as $c) {
            foreach ($c->assignments as $as) {
                $uid = (int) $as->user_id;
                $dialogsByUser[$uid] = ($dialogsByUser[$uid] ?? 0) + 1;
            }
        }

        $unansweredByUser = [];
        foreach ($chats as $c) {
            if ($c->is_archived || $c->last_message_direction !== 'inbound') {
                continue;
            }
            foreach ($c->assignments as $as) {
                $uid = (int) $as->user_id;
                $unansweredByUser[$uid] = ($unansweredByUser[$uid] ?? 0) + 1;
            }
        }

        $rows = [];
        foreach ($userIds as $uid) {
            $uid = (int) $uid;
            $pairs = $pairStats['pairs_by_user'][$uid] ?? [];
            $avg = $pairs === [] ? null : round(array_sum($pairs) / count($pairs));
            $max = $pairs === [] ? null : max($pairs);
            $pairCount = count($pairs);
            $slaMet = 0;
            foreach ($pairs as $p) {
                if ($p <= $this->slaSeconds) {
                    $slaMet++;
                }
            }
            $slaPct = $pairCount > 0 ? round(100 * $slaMet / $pairCount, 1) : null;

            $u = $users->get($uid);
            $rows[] = [
                'user_id' => $uid,
                'name' => $u?->name ?? '—',
                'department_id' => $u?->department_id,
                'department_ids' => $u?->departments?->pluck('id')->map(fn ($v) => (int) $v)->all() ?? [],
                'dialog_count' => $dialogsByUser[$uid] ?? 0,
                'avg_response_seconds' => $avg,
                'max_response_seconds' => $max,
                'unanswered_dialogs' => $unansweredByUser[$uid] ?? 0,
                'closed_dialogs' => $closedByUser[$uid] ?? 0,
                'avg_client_rating' => null,
                'sla_on_time_percent' => $slaPct,
            ];
        }

        usort($rows, static fn (array $a, array $b): int => ($b['dialog_count'] <=> $a['dialog_count']));

        return $rows;
    }

    /**
     * При фильтре по отделу в таблице сотрудников оставляем только тех, у кого users.department_id совпадает
     * (назначения из других отделов на чаты с тегом отдела не показываем в этом срезе).
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function filterEmployeeStatsByDepartment(array $rows, DialogAnalyticsFilters $filters): array
    {
        if ($filters->departmentId === null) {
            return $rows;
        }

        $did = $filters->departmentId;

        return array_values(array_filter(
            $rows,
            static function (array $r) use ($did): bool {
                $deptIds = $r['department_ids'] ?? null;
                if (is_array($deptIds) && in_array($did, array_map('intval', $deptIds), true)) {
                    return true;
                }

                return isset($r['department_id']) && (int) $r['department_id'] === $did;
            },
        ));
    }

    /**
     * @param  list<array<string, mixed>>  $employeeStats
     * @return array<string, list<array<string, mixed>>>
     */
    private function buildRankings(array $employeeStats): array
    {
        $lim = self::RANKING_LIMIT;

        $withAvg = array_values(array_filter(
            $employeeStats,
            static fn (array $r): bool => isset($r['avg_response_seconds']) && $r['avg_response_seconds'] !== null,
        ));
        usort($withAvg, static fn (array $a, array $b): int => ($a['avg_response_seconds'] <=> $b['avg_response_seconds']));
        $fastest = array_slice($withAvg, 0, $lim);

        $slowest = $withAvg;
        usort($slowest, static fn (array $a, array $b): int => ($b['avg_response_seconds'] <=> $a['avg_response_seconds']));
        $slowest = array_slice($slowest, 0, $lim);

        $byUnanswered = [...$employeeStats];
        usort($byUnanswered, static fn (array $a, array $b): int => ($b['unanswered_dialogs'] <=> $a['unanswered_dialogs']));
        $mostUnanswered = array_slice($byUnanswered, 0, $lim);

        $byDialogs = [...$employeeStats];
        usort($byDialogs, static fn (array $a, array $b): int => ($b['dialog_count'] <=> $a['dialog_count']));
        $mostDialogs = array_slice($byDialogs, 0, $lim);

        $withSla = array_values(array_filter(
            $employeeStats,
            static fn (array $r): bool => isset($r['sla_on_time_percent']) && $r['sla_on_time_percent'] !== null,
        ));
        $bestSla = $withSla;
        usort($bestSla, static fn (array $a, array $b): int => ($b['sla_on_time_percent'] <=> $a['sla_on_time_percent']));
        $bestSla = array_slice($bestSla, 0, $lim);

        $worstSla = $withSla;
        usort($worstSla, static fn (array $a, array $b): int => ($a['sla_on_time_percent'] <=> $b['sla_on_time_percent']));
        $worstSla = array_slice($worstSla, 0, $lim);

        return [
            'fastest_avg_response' => $fastest,
            'slowest_avg_response' => $slowest,
            'most_unanswered' => $mostUnanswered,
            'most_dialogs' => $mostDialogs,
            'best_sla' => $bestSla,
            'worst_sla' => $worstSla,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $employeeStats
     * @param  Collection<int, Chat>  $chats
     * @param  array<int>  $chatIds
     * @param  array<string, mixed>  $pairStats
     * @return list<array<string, mixed>>
     */
    private function buildDepartmentStats(
        array $employeeStats,
        Collection $chats,
        array $chatIds,
        array $pairStats,
        DialogAnalyticsFilters $filters,
    ): array {
        $deptIds = Department::query()->orderBy('name')->pluck('id');
        $rows = [];

        foreach ($deptIds as $did) {
            $did = (int) $did;
            $deptChats = $chats->filter(function (Chat $c) use ($did): bool {
                return $c->departments->contains('id', $did);
            });
            if ($deptChats->isEmpty()) {
                continue;
            }

            $staffIds = User::query()
                ->whereHas('departments', static fn ($q) => $q->where('departments.id', $did))
                ->pluck('id')
                ->all();
            $pairs = [];
            foreach ($staffIds as $sid) {
                $sid = (int) $sid;
                foreach ($pairStats['pairs_by_user'][$sid] ?? [] as $p) {
                    $pairs[] = $p;
                }
            }
            $avg = $pairs === [] ? null : round(array_sum($pairs) / count($pairs));
            $maxDelay = $pairs === [] ? null : max($pairs);

            $active = $deptChats->where('is_archived', false)->count();
            $overdueDialogs = 0;
            foreach ($deptChats as $c) {
                if (! $c->is_archived && $c->last_message_direction === 'inbound' && $c->last_message_at) {
                    $wait = now()->diffInSeconds(Carbon::parse($c->last_message_at));
                    if ($wait > $this->slaSeconds) {
                        $overdueDialogs++;
                    }
                }
            }

            $best = null;
            $bestName = null;
            foreach ($employeeStats as $es) {
                $esDeptIds = is_array($es['department_ids'] ?? null) ? array_map('intval', $es['department_ids']) : [];
                $matchesDept = in_array($did, $esDeptIds, true)
                    || (int) ($es['department_id'] ?? 0) === $did;
                if (! $matchesDept) {
                    continue;
                }
                $avgU = $es['avg_response_seconds'];
                if ($avgU === null) {
                    continue;
                }
                if ($best === null || $avgU < $best) {
                    $best = $avgU;
                    $bestName = $es['name'];
                }
            }

            $dept = Department::query()->find($did);

            $rows[] = [
                'department_id' => $did,
                'name' => $dept?->name ?? '—',
                'dialog_count' => $deptChats->count(),
                'avg_response_seconds' => $avg,
                'max_delay_seconds' => $maxDelay,
                'active_dialogs' => $active,
                'overdue_dialogs' => $overdueDialogs,
                'best_employee_name' => $bestName,
            ];
        }

        return $rows;
    }

    /**
     * @param  array<string, int>  $dailyBuckets
     * @param  array<string, mixed>  $pairStats
     * @param  list<array<string, mixed>>  $employeeStats
     * @param  Collection<int, Chat>  $chats
     * @param  array<int>  $chatIds
     * @return array<string, mixed>
     */
    private function buildChartData(
        array $dailyBuckets,
        array $pairStats,
        array $employeeStats,
        Collection $chats,
        DialogAnalyticsFilters $filters,
    ): array {
        $dialogsOverTime = [];
        foreach ($dailyBuckets as $day => $cnt) {
            $dialogsOverTime[] = ['date' => $day, 'count' => $cnt];
        }

        $statusDist = [
            'active' => $chats->where('is_archived', false)->count(),
            'closed' => $chats->where('is_archived', true)->count(),
            'waiting' => $chats->filter(fn (Chat $c) => ! $c->is_archived && $c->last_message_direction === 'inbound')->count(),
        ];

        $load = [];
        foreach ($employeeStats as $es) {
            if (($es['dialog_count'] ?? 0) > 0) {
                $load[] = [
                    'name' => $es['name'],
                    'dialogs' => $es['dialog_count'],
                ];
            }
        }
        usort($load, static fn (array $a, array $b): int => ($b['dialogs'] <=> $a['dialogs']));
        $load = array_slice($load, 0, 15);

        $topFast = $employeeStats;
        usort($topFast, static function (array $a, array $b): int {
            $av = $a['avg_response_seconds'];
            $bv = $b['avg_response_seconds'];
            if ($av === null && $bv === null) {
                return 0;
            }
            if ($av === null) {
                return 1;
            }
            if ($bv === null) {
                return -1;
            }

            return $av <=> $bv;
        });
        $topFast = array_values(array_filter(array_slice($topFast, 0, 5), static fn (array $r) => $r['avg_response_seconds'] !== null));

        $problemPreview = [];
        foreach ($this->collectProblematicRows($chats, $pairStats['max_gap_by_chat'] ?? []) as $row) {
            $problemPreview[] = $row;
        }
        usort($problemPreview, static fn (array $a, array $b): int => ($b['wait_seconds'] ?? 0) <=> ($a['wait_seconds'] ?? 0));
        $problemPreview = array_slice($problemPreview, 0, 10);

        return [
            'dialogs_over_time' => $dialogsOverTime,
            'avg_response_by_day' => $this->averageResponseByDay($dailyBuckets, $pairStats),
            'load_per_employee' => $load,
            'status_distribution' => $statusDist,
            'top_fastest_employees' => $topFast,
            'top_waiting_preview' => $problemPreview,
        ];
    }

    /**
     * @param  array<string, int>  $dailyBuckets
     * @param  array<string, mixed>  $pairStats
     * @return list<array{date: string, avg_seconds: ?float}>
     */
    private function averageResponseByDay(array $dailyBuckets, array $pairStats): array
    {
        $byDay = $pairStats['response_seconds_by_day'] ?? [];
        $out = [];
        foreach (array_keys($dailyBuckets) as $day) {
            $vals = $byDay[$day] ?? [];
            $avg = $vals === [] ? null : round(array_sum($vals) / count($vals));
            $out[] = ['date' => $day, 'avg_seconds' => $avg];
        }

        return $out;
    }

    /**
     * @param  Collection<int, Chat>  $chats
     * @param  array<int, int>  $maxGapByChat
     * @return list<array<string, mixed>>
     */
    private function collectProblematicRows(Collection $chats, array $maxGapByChat): array
    {
        $rows = [];
        foreach ($chats as $c) {
            $reasons = [];
            $waitSeconds = 0;

            if (! $c->is_archived && $c->last_message_direction === 'inbound' && $c->last_message_at) {
                $waitSeconds = max($waitSeconds, now()->diffInSeconds(Carbon::parse($c->last_message_at)));
                if ($waitSeconds > $this->slaSeconds) {
                    $reasons[] = 'sla_overdue';
                }
                $reasons[] = 'awaiting_reply';
            }

            $gap = $maxGapByChat[$c->id] ?? 0;
            if ($gap > self::STUCK_IDLE_SECONDS) {
                $reasons[] = 'idle_stuck';
                $waitSeconds = max($waitSeconds, $gap);
            }

            if ($c->is_archived && $c->created_at && $c->updated_at) {
                $dur = $c->updated_at->diffInSeconds(Carbon::parse($c->created_at));
                if ($dur > 7 * 86400) {
                    $reasons[] = 'long_close';
                }
            }

            if ($reasons === []) {
                continue;
            }

            $assign = $c->assignments->first();
            $dept = $c->departments->first();

            $rows[] = [
                'chat_id' => $c->id,
                'client_label' => $c->contact?->name ?? $c->chat_name ?? $c->whatsapp_chat_id,
                'client_phone' => $c->contact?->phone_number ?? null,
                'assignee_name' => $assign?->user?->name,
                'department_name' => $dept?->name,
                'last_client_message_at' => $c->last_message_at?->toIso8601String(),
                'wait_seconds' => $waitSeconds,
                'status' => $c->is_archived ? 'closed' : ($c->last_message_direction === 'inbound' ? 'waiting' : 'active'),
                'reasons' => array_values(array_unique($reasons)),
            ];
        }

        return $rows;
    }

    /**
     * @param  Collection<int, Chat>  $chats
     * @param  Collection<int, Message>  $messages
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    private function buildProblematicChats(Collection $chats, Collection $messages, DialogAnalyticsFilters $filters): array
    {
        $maxGap = [];
        foreach ($messages->groupBy('chat_id') as $cid => $grp) {
            $prev = null;
            foreach ($grp as $m) {
                $t = $this->messageTime($m);
                if ($prev !== null) {
                    $maxGap[(int) $cid] = max($maxGap[(int) $cid] ?? 0, $t->diffInSeconds($prev));
                }
                $prev = $t;
            }
        }

        $rows = $this->collectProblematicRows($chats, $maxGap);
        usort($rows, static fn (array $a, array $b): int => ($b['wait_seconds'] ?? 0) <=> ($a['wait_seconds'] ?? 0));

        $page = max(1, $filters->page);
        $per = min(50, max(5, $filters->perPage));
        $total = count($rows);
        $offset = ($page - 1) * $per;
        $slice = array_slice($rows, $offset, $per);

        $paginator = new LengthAwarePaginator(
            $slice,
            $total,
            $per,
            $page,
            ['path' => request()->url(), 'query' => request()->query()],
        );

        return [
            'data' => array_map(function (array $row): array {
                $row['open_url'] = route('chats.show', ['chat' => $row['chat_id']]);

                return $row;
            }, $slice),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyPayload(DialogAnalyticsFilters $filters): array
    {
        return [
            'sla_seconds' => $this->slaSeconds,
            'summary' => [
                'total_dialogs' => 0,
                'active_dialogs' => 0,
                'closed_dialogs' => 0,
                'avg_first_response_seconds' => null,
                'avg_response_seconds' => null,
                'max_client_wait_seconds' => null,
                'unanswered_dialogs' => 0,
                'avg_idle_before_new_chat_seconds' => null,
                'avg_time_to_close_seconds' => null,
                'overdue_response_percent' => null,
                'dialogs_per_staff_member' => null,
            ],
            'employee_stats' => [],
            'department_stats' => [],
            'rankings' => [
                'fastest_avg_response' => [],
                'slowest_avg_response' => [],
                'most_unanswered' => [],
                'most_dialogs' => [],
                'best_sla' => [],
                'worst_sla' => [],
            ],
            'chart_data' => [
                'dialogs_over_time' => [],
                'avg_response_by_day' => [],
                'load_per_employee' => [],
                'status_distribution' => ['active' => 0, 'closed' => 0, 'waiting' => 0],
                'top_fastest_employees' => [],
                'top_waiting_preview' => [],
            ],
            'problematic_chats' => [
                'data' => [],
                'meta' => [
                    'current_page' => 1,
                    'last_page' => 1,
                    'per_page' => $filters->perPage,
                    'total' => 0,
                ],
            ],
        ];
    }

    /**
     * @return array<int>
     */
    private function filteredChatIds(User $user, DialogAnalyticsFilters $filters): array
    {
        $q = $this->scopedChatsQuery($user, $filters);
        $this->applyFilters($q, $filters);

        return $q->pluck('id')->all();
    }

    private function scopedChatsQuery(User $user, DialogAnalyticsFilters $filters): Builder
    {
        $q = Chat::query();

        if ($user->hasRole('administrator')) {
            // all
        } elseif ($user->hasRole('manager')) {
            $managerDeptIds = $user->departmentIds();
            $departmentUserIds = $managerDeptIds === []
                ? collect()
                : User::query()
                    ->whereHas('departments', static fn ($qq) => $qq->whereIn('departments.id', $managerDeptIds))
                    ->pluck('id');

            $q->where(function (Builder $inner) use ($departmentUserIds, $managerDeptIds): void {
                $inner->whereHas('assignments', static fn (Builder $aq) => $aq->whereIn('user_id', $departmentUserIds));
                if ($managerDeptIds !== []) {
                    $inner->orWhereHas('departments', static fn (Builder $dq) => $dq->whereIn('departments.id', $managerDeptIds));
                }
            });
        } else {
            $q->whereHas('assignments', static fn (Builder $aq) => $aq->where('user_id', $user->id));
        }

        return $q;
    }

    /**
     * Фильтры чатов: период по активности сообщений; опционально отдел (тег chat_department),
     * сотрудник (назначение), статус, канал. Комбинация department_id + employee_id: чаты,
     * у которых есть и тег отдела, и назначение на этого сотрудника.
     */
    private function applyFilters(Builder $q, DialogAnalyticsFilters $filters): void
    {
        $from = $filters->from;
        $to = $filters->to;

        $q->whereHas('messages', static function (Builder $mq) use ($from, $to): void {
            $mq->whereRaw('COALESCE(message_timestamp, created_at) >= ?', [$from])
                ->whereRaw('COALESCE(message_timestamp, created_at) <= ?', [$to]);
        });

        match ($filters->status) {
            'active' => $q->where('is_archived', false),
            'closed' => $q->where('is_archived', true),
            'waiting' => $q->where('is_archived', false)->where('last_message_direction', 'inbound'),
            default => null,
        };

        match ($filters->channel) {
            'whatsapp' => $q->whereNotNull('whatsapp_session_id'),
            'telegram', 'site' => $q->whereRaw('1 = 0'),
            default => null,
        };

        if ($filters->departmentId !== null) {
            $did = $filters->departmentId;
            $q->whereHas('departments', static fn (Builder $dq) => $dq->where('departments.id', $did));
        }

        if ($filters->employeeId !== null) {
            $eid = $filters->employeeId;
            $q->whereHas('assignments', static fn (Builder $aq) => $aq->where('user_id', $eid));
        }
    }
}
