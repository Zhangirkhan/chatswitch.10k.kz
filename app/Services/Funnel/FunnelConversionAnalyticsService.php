<?php

declare(strict_types=1);

namespace App\Services\Funnel;

use App\Models\Chat;
use App\Models\ChatFunnelTransition;
use App\Models\Funnel;
use App\Models\User;
use App\Support\TenantCompany;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class FunnelConversionAnalyticsService
{
    public function __construct(
        private readonly FunnelStageResponseTimeAnalytics $responseTimeAnalytics,
    ) {}

    /**
     * @return array{
     *     period: array{from: string, to: string},
     *     summary: array{tracked_chats: int, total_transitions: int, funnels_with_data: int},
     *     funnels: list<array<string, mixed>>
     * }
     */
    public function build(
        User $user,
        Carbon $from,
        Carbon $to,
        ?int $funnelId = null,
        ?int $departmentId = null,
        ?int $employeeId = null,
    ): array {
        $chatIds = $this->scopedChatIds($user, $from, $to, $departmentId, $employeeId);

        $funnelsQuery = Funnel::query()
            ->where('company_id', TenantCompany::id())
            ->with(['stages' => fn ($q) => $q->where('is_active', true)->orderBy('position')])
            ->orderBy('position')
            ->orderBy('id');

        if ($funnelId !== null) {
            $funnelsQuery->whereKey($funnelId);
        }

        $funnels = $funnelsQuery->get();
        $stageIds = $funnels->flatMap(fn (Funnel $funnel) => $funnel->stages->pluck('id'))->map(fn ($id) => (int) $id)->all();

        if ($stageIds === [] || $chatIds === []) {
            return [
                'period' => [
                    'from' => $from->toIso8601String(),
                    'to' => $to->toIso8601String(),
                ],
                'summary' => [
                    'tracked_chats' => count($chatIds),
                    'total_transitions' => 0,
                    'funnels_with_data' => 0,
                ],
                'funnels' => [],
            ];
        }

        $entriesByStage = $this->entriesByStage($chatIds, $stageIds, $from, $to);
        $forwardExitsByStage = $this->forwardExitsByStage($funnels, $chatIds, $from, $to);
        $currentByStage = $this->currentChatsByStage($chatIds, $stageIds);
        $avgHoursByStage = $this->avgHoursOnStage($chatIds, $stageIds, $from, $to);
        $responseMinutesByStage = $this->responseTimeAnalytics->avgMinutesByStage($chatIds, $stageIds, $from, $to);

        $totalTransitions = (int) ChatFunnelTransition::query()
            ->whereIn('chat_id', $chatIds)
            ->whereBetween('created_at', [$from, $to])
            ->count();

        $funnelsWithData = 0;
        $rows = [];

        foreach ($funnels as $funnel) {
            $stages = $funnel->stages->values();
            if ($stages->isEmpty()) {
                continue;
            }

            $stageRows = [];
            $hasData = false;
            $firstEntries = null;
            $lastEntries = null;

            foreach ($stages as $index => $stage) {
                $stageId = (int) $stage->id;
                $entries = (int) ($entriesByStage[$stageId] ?? 0);
                $forward = (int) ($forwardExitsByStage[$stageId] ?? 0);
                $current = (int) ($currentByStage[$stageId] ?? 0);

                if ($entries > 0 || $forward > 0 || $current > 0) {
                    $hasData = true;
                }

                if ($index === 0) {
                    $firstEntries = $entries;
                }
                if ($index === $stages->count() - 1) {
                    $lastEntries = $entries;
                }

                $stageRows[] = [
                    'id' => $stageId,
                    'name' => $stage->name,
                    'position' => (int) $stage->position,
                    'color' => $stage->color,
                    'current_chats' => $current,
                    'entries' => $entries,
                    'forward_exits' => $forward,
                    'conversion_percent' => $entries > 0 ? round($forward * 100 / $entries, 1) : null,
                    'drop_off' => max(0, $entries - $forward),
                    'avg_hours_on_stage' => isset($avgHoursByStage[$stageId])
                        ? round((float) $avgHoursByStage[$stageId], 1)
                        : null,
                    'avg_response_minutes_ai' => $responseMinutesByStage[$stageId]['avg_response_minutes_ai'] ?? null,
                    'avg_response_minutes_manager' => $responseMinutesByStage[$stageId]['avg_response_minutes_manager'] ?? null,
                    'response_samples_ai' => $responseMinutesByStage[$stageId]['response_samples_ai'] ?? 0,
                    'response_samples_manager' => $responseMinutesByStage[$stageId]['response_samples_manager'] ?? 0,
                    'is_final' => $index === $stages->count() - 1,
                ];
            }

            if ($hasData) {
                $funnelsWithData++;
            }

            $overall = null;
            if ($firstEntries !== null && $firstEntries > 0 && $lastEntries !== null) {
                $overall = round($lastEntries * 100 / $firstEntries, 1);
            }

            $rows[] = [
                'id' => $funnel->id,
                'name' => $funnel->name,
                'color' => $funnel->color,
                'is_active' => (bool) $funnel->is_active,
                'overall_conversion_percent' => $overall,
                'stages' => $stageRows,
            ];
        }

        return [
            'period' => [
                'from' => $from->toIso8601String(),
                'to' => $to->toIso8601String(),
            ],
            'summary' => [
                'tracked_chats' => count($chatIds),
                'total_transitions' => $totalTransitions,
                'funnels_with_data' => $funnelsWithData,
            ],
            'funnels' => $rows,
        ];
    }

    /**
     * @return array<int, int>
     */
    private function entriesByStage(array $chatIds, array $stageIds, Carbon $from, Carbon $to): array
    {
        return ChatFunnelTransition::query()
            ->select('to_stage_id', DB::raw('COUNT(DISTINCT chat_id) as total'))
            ->whereIn('chat_id', $chatIds)
            ->whereIn('to_stage_id', $stageIds)
            ->whereBetween('created_at', [$from, $to])
            ->whereNotNull('to_stage_id')
            ->groupBy('to_stage_id')
            ->pluck('total', 'to_stage_id')
            ->mapWithKeys(fn ($total, $stageId) => [(int) $stageId => (int) $total])
            ->all();
    }

    /**
     * @param  Collection<int, Funnel>  $funnels
     * @return array<int, int>
     */
    private function forwardExitsByStage(Collection $funnels, array $chatIds, Carbon $from, Carbon $to): array
    {
        $nextStageById = [];
        foreach ($funnels as $funnel) {
            $ordered = $funnel->stages->values();
            foreach ($ordered as $index => $stage) {
                $next = $ordered[$index + 1] ?? null;
                if ($next !== null) {
                    $nextStageById[(int) $stage->id] = (int) $next->id;
                }
            }
        }

        if ($nextStageById === []) {
            return [];
        }

        $counts = [];
        foreach ($nextStageById as $fromStageId => $toStageId) {
            $counts[$fromStageId] = (int) ChatFunnelTransition::query()
                ->whereIn('chat_id', $chatIds)
                ->where('from_stage_id', $fromStageId)
                ->where('to_stage_id', $toStageId)
                ->whereBetween('created_at', [$from, $to])
                ->distinct()
                ->count('chat_id');
        }

        return $counts;
    }

    /**
     * @return array<int, int>
     */
    private function currentChatsByStage(array $chatIds, array $stageIds): array
    {
        return Chat::query()
            ->select('funnel_stage_id', DB::raw('COUNT(*) as total'))
            ->whereIn('id', $chatIds)
            ->whereIn('funnel_stage_id', $stageIds)
            ->where('is_archived', false)
            ->groupBy('funnel_stage_id')
            ->pluck('total', 'funnel_stage_id')
            ->mapWithKeys(fn ($total, $stageId) => [(int) $stageId => (int) $total])
            ->all();
    }

    /**
     * @return array<int, float>
     */
    private function avgHoursOnStage(array $chatIds, array $stageIds, Carbon $from, Carbon $to): array
    {
        $driver = DB::connection()->getDriverName();
        $secondsExpr = match ($driver) {
            'sqlite' => '(strftime(\'%s\', t_out.created_at) - strftime(\'%s\', t_in.created_at))',
            default => 'TIMESTAMPDIFF(SECOND, t_in.created_at, t_out.created_at)',
        };

        $rows = DB::table('chat_funnel_transitions as t_out')
            ->join('chat_funnel_transitions as t_in', function ($join): void {
                $join->on('t_in.chat_id', '=', 't_out.chat_id')
                    ->on('t_in.to_stage_id', '=', 't_out.from_stage_id')
                    ->whereColumn('t_in.created_at', '<', 't_out.created_at');
            })
            ->select([
                't_out.from_stage_id as stage_id',
                DB::raw("AVG({$secondsExpr}) as avg_seconds"),
            ])
            ->whereIn('t_out.chat_id', $chatIds)
            ->whereIn('t_out.from_stage_id', $stageIds)
            ->whereBetween('t_out.created_at', [$from, $to])
            ->whereNotNull('t_out.from_stage_id')
            ->groupBy('t_out.from_stage_id')
            ->get();

        $result = [];
        foreach ($rows as $row) {
            $seconds = (float) ($row->avg_seconds ?? 0);
            if ($seconds > 0) {
                $result[(int) $row->stage_id] = $seconds / 3600;
            }
        }

        return $result;
    }

    /**
     * @return list<int>
     */
    private function scopedChatIds(
        User $user,
        Carbon $from,
        Carbon $to,
        ?int $departmentId,
        ?int $employeeId,
    ): array {
        $q = $this->scopedChatsQuery($user);

        $q->where(function (Builder $inner) use ($from, $to): void {
            $inner->whereHas('messages', static function (Builder $mq) use ($from, $to): void {
                $mq->whereRaw('COALESCE(message_timestamp, created_at) >= ?', [$from])
                    ->whereRaw('COALESCE(message_timestamp, created_at) <= ?', [$to]);
            })->orWhereHas('funnelTransitions', static function (Builder $tq) use ($from, $to): void {
                $tq->whereBetween('created_at', [$from, $to]);
            });
        });

        if ($departmentId !== null) {
            $q->whereHas('departments', static fn (Builder $dq) => $dq->where('departments.id', $departmentId));
        }

        if ($employeeId !== null) {
            $q->whereHas('assignments', static fn (Builder $aq) => $aq->where('user_id', $employeeId));
        }

        $companyId = TenantCompany::id();
        if ($companyId > 0) {
            $q->where('company_id', $companyId);
        }

        return $q->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    private function scopedChatsQuery(User $user): Builder
    {
        $q = Chat::query()->where('is_group', false);

        if ($user->hasRole('administrator')) {
            return $q;
        }

        if ($user->hasRole('manager')) {
            $managerDeptIds = $user->departmentIds();
            $departmentUserIds = $managerDeptIds === []
                ? collect()
                : User::query()
                    ->whereHas('departments', static fn (Builder $qq) => $qq->whereIn('departments.id', $managerDeptIds))
                    ->pluck('id');

            return $q->where(function (Builder $inner) use ($departmentUserIds, $managerDeptIds): void {
                $inner->whereHas('assignments', static fn (Builder $aq) => $aq->whereIn('user_id', $departmentUserIds));
                if ($managerDeptIds !== []) {
                    $inner->orWhereHas('departments', static fn (Builder $dq) => $dq->whereIn('departments.id', $managerDeptIds));
                }
            });
        }

        return $q->whereHas('assignments', static fn (Builder $aq) => $aq->where('user_id', $user->id));
    }
}
