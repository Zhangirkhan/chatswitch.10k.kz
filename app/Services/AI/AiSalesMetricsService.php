<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\AiOrchestratorAction;
use App\Models\Chat;
use App\Models\ChatFunnelTransition;
use App\Models\Company;
use App\Models\DealOutcome;
use App\Models\FunnelStage;
use App\Models\Message;
use App\Models\SalesMilestone;
use App\Models\ScheduledMessage;
use App\Models\User;
use App\Services\SuperAdmin\SuperAdminCompanyScope;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class AiSalesMetricsService
{
    private const MIN_CLOSED_DEALS = 3;

    private const FOLLOW_UP_RESPONSE_WINDOW_HOURS = 72;

    /** @var list<string> */
    private const PROPOSAL_STAGE_PATTERNS = ['предлож', 'коммерч', 'оффер', 'offer', 'proposal'];

    public function __construct(
        private readonly SuperAdminCompanyScope $superAdminScope,
        private readonly ObjectionIntelligenceService $objectionIntel,
    ) {}

    /**
     * @return array{
     *     period: array{from: string, to: string},
     *     filters: array{company_id: int|null, company_name: string|null},
     *     summary: array{cohort_size: int, closed_deals: int, follow_ups_sent: int},
     *     kpis: list<array{key: string, label: string, percent: float|null, numerator: int, denominator: int, sufficient_data: bool}>,
     *     lost_reasons: list<array{reason: string, count: int, percent: float}>,
     *     win_rate_by_grade: list<array{grade: string, won: int, total: int, percent: float|null}>,
     *     objection_intelligence: array{top_objections: list<array<string, mixed>>, top_winning_responses: list<array<string, mixed>>, top_losing_responses: list<array<string, mixed>>},
     *     by_company: list<array<string, mixed>>
     * }
     */
    public function build(User $superAdmin, Carbon $from, Carbon $to, ?int $companyId = null): array
    {
        $companyIds = $this->resolveCompanyIds($superAdmin, $companyId);
        $companyName = null;
        if ($companyId !== null) {
            $companyName = Company::query()->whereKey($companyId)->value('name');
        }

        $cohortChatIds = $this->cohortChatIds($companyIds, $from, $to);
        $cohortSize = count($cohortChatIds);

        $closedDeals = $this->closedDealsQuery($companyIds, $from, $to)->count();
        $followUpsSent = $this->followUpsSentCount($companyIds, $from, $to);

        $kpis = $this->buildKpis($companyIds, $cohortChatIds, $from, $to, $closedDeals, $followUpsSent);
        $lostReasons = $this->lostReasonDistribution($companyIds, $from, $to);
        $winRateByGrade = $this->winRateByGrade($companyIds, $from, $to);
        $objectionIntelligence = $companyId !== null
            ? $this->objectionIntel->buildForCompany($companyId)
            : $this->aggregateObjectionIntelligence($companyIds);

        $byCompany = [];
        if ($companyId === null && $companyIds !== []) {
            $byCompany = $this->buildByCompanyBreakdown($superAdmin, $companyIds, $from, $to);
        }

        return [
            'period' => [
                'from' => $from->toIso8601String(),
                'to' => $to->toIso8601String(),
            ],
            'filters' => [
                'company_id' => $companyId,
                'company_name' => is_string($companyName) ? $companyName : null,
            ],
            'summary' => [
                'cohort_size' => $cohortSize,
                'closed_deals' => $closedDeals,
                'follow_ups_sent' => $followUpsSent,
            ],
            'kpis' => $kpis,
            'lost_reasons' => $lostReasons,
            'win_rate_by_grade' => $winRateByGrade,
            'objection_intelligence' => $objectionIntelligence,
            'by_company' => $byCompany,
        ];
    }

    /**
     * @return list<int>
     */
    public function resolveCompanyIds(User $superAdmin, ?int $companyId): array
    {
        $query = $this->superAdminScope->applyToCompaniesQuery(Company::query(), $superAdmin);

        if ($companyId !== null) {
            $company = Company::query()->find($companyId);
            abort_if($company === null, 404);
            $this->superAdminScope->ensureCanManage($superAdmin, $company);
            $query->whereKey($companyId);
        }

        return $query->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * @param  list<int>  $companyIds
     * @return list<int>
     */
    public function cohortChatIds(array $companyIds, Carbon $from, Carbon $to): array
    {
        if ($companyIds === []) {
            return [];
        }

        return Chat::query()
            ->withoutGlobalScope('tenant')
            ->whereIn('company_id', $companyIds)
            ->where('is_group', false)
            ->whereHas('messages', static function (Builder $query) use ($from, $to): void {
                $query->where('direction', 'inbound')
                    ->whereRaw('COALESCE(message_timestamp, created_at) >= ?', [$from])
                    ->whereRaw('COALESCE(message_timestamp, created_at) <= ?', [$to]);
            })
            ->where(function (Builder $query) use ($from, $to): void {
                $query->where('ai_enabled', true);

                if (Schema::hasTable('ai_response_logs')) {
                    $query->orWhereExists(static function ($sub) use ($from, $to): void {
                        $sub->select(DB::raw(1))
                            ->from('ai_response_logs')
                            ->whereColumn('ai_response_logs.chat_id', 'chats.id')
                            ->whereBetween('ai_response_logs.created_at', [$from, $to]);
                    });
                }

                if (Schema::hasTable('ai_orchestrator_runs')) {
                    $query->orWhereExists(static function ($sub) use ($from, $to): void {
                        $sub->select(DB::raw(1))
                            ->from('ai_orchestrator_runs')
                            ->whereColumn('ai_orchestrator_runs.chat_id', 'chats.id')
                            ->whereBetween('ai_orchestrator_runs.created_at', [$from, $to]);
                    });
                }
            })
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * @param  list<int>  $companyIds
     * @param  list<int>  $cohortChatIds
     * @return list<array{key: string, label: string, percent: float|null, numerator: int, denominator: int, sufficient_data: bool}>
     */
    private function buildKpis(
        array $companyIds,
        array $cohortChatIds,
        Carbon $from,
        Carbon $to,
        int $closedDeals,
        int $followUpsSent,
    ): array {
        $cohortSize = count($cohortChatIds);

        $qualified = $this->countMilestoneOrFlag($cohortChatIds, SalesMilestone::MILESTONE_QUALIFIED, 'qualified', $from, $to);
        $budgetKnown = $this->countMilestoneOrFlag($cohortChatIds, SalesMilestone::MILESTONE_BUDGET_CAPTURED, 'budget_known', $from, $to);
        $dmKnown = $this->countMilestoneOrFlag($cohortChatIds, SalesMilestone::MILESTONE_DM_CAPTURED, 'decision_maker_known', $from, $to);
        $requirementsKnown = $this->countMilestoneOrFlag($cohortChatIds, SalesMilestone::MILESTONE_REQUIREMENTS_CAPTURED, 'requirements_known', $from, $to);
        $timelineKnown = $this->countMilestoneOrFlag($cohortChatIds, SalesMilestone::MILESTONE_TIMELINE_CAPTURED, 'timeline_known', $from, $to);
        $proposalChats = $this->proposalChatCount($companyIds, $cohortChatIds, $from, $to);
        $bookingChats = $this->meetingBookingChatCount($companyIds, $cohortChatIds, $from, $to);

        $wonCount = (int) $this->closedDealsQuery($companyIds, $from, $to)->where('won', true)->count();
        $followUpResponses = $this->followUpResponseCount($companyIds, $from, $to);
        $nurtureResponses = $this->followUpResponseCount($companyIds, $from, $to, ScheduledMessage::PURPOSE_NURTURE_FOLLOW_UP);
        $funnelResponses = $this->followUpResponseCount($companyIds, $from, $to, ScheduledMessage::PURPOSE_FUNNEL_FOLLOW_UP);
        $nurtureSent = $this->followUpsSentCount($companyIds, $from, $to, ScheduledMessage::PURPOSE_NURTURE_FOLLOW_UP);
        $funnelSent = $this->followUpsSentCount($companyIds, $from, $to, ScheduledMessage::PURPOSE_FUNNEL_FOLLOW_UP);
        $deferrals = $this->countMilestoneInPeriod($cohortChatIds, SalesMilestone::MILESTONE_DEFERRAL, $from, $to);
        $deferralRecoveries = $this->deferralRecoveryCount($cohortChatIds, $from, $to);

        return [
            $this->kpi('qualification_rate', 'Qualification Rate', $qualified, $cohortSize, $cohortSize > 0),
            $this->kpi('budget_capture_rate', 'Budget Capture Rate', $budgetKnown, $cohortSize, $cohortSize > 0),
            $this->kpi('requirements_capture_rate', 'Requirements Capture Rate', $requirementsKnown, $cohortSize, $cohortSize > 0),
            $this->kpi('timeline_capture_rate', 'Timeline Capture Rate', $timelineKnown, $cohortSize, $cohortSize > 0),
            $this->kpi('dm_capture_rate', 'DM Capture Rate', $dmKnown, $cohortSize, $cohortSize > 0),
            $this->kpi('proposal_rate', 'Proposal Rate', $proposalChats, $cohortSize, $cohortSize > 0),
            $this->kpi('meeting_booking_rate', 'Meeting Booking Rate', $bookingChats, $cohortSize, $cohortSize > 0),
            $this->kpi('close_rate', 'Close Rate', $wonCount, $closedDeals, $closedDeals >= self::MIN_CLOSED_DEALS),
            $this->kpi('follow_up_response_rate', 'Follow-up Response Rate', $followUpResponses, $followUpsSent, $followUpsSent > 0),
            $this->kpi('nurture_response_rate', 'Nurture Response Rate', $nurtureResponses, $nurtureSent, $nurtureSent > 0),
            $this->kpi('funnel_follow_up_response_rate', 'Funnel Follow-up Response Rate', $funnelResponses, $funnelSent, $funnelSent > 0),
            $this->kpi('deferral_recovery_rate', 'Deferral Recovery Rate', $deferralRecoveries, $deferrals, $deferrals > 0),
        ];
    }

    /**
     * @param  list<int>  $cohortChatIds
     */
    private function countMilestoneOrFlag(
        array $cohortChatIds,
        string $milestone,
        string $flag,
        Carbon $from,
        Carbon $to,
    ): int {
        $milestoneCount = $this->countMilestoneInPeriod($cohortChatIds, $milestone, $from, $to);
        if ($milestoneCount > 0) {
            return $milestoneCount;
        }

        return $this->countSalesStateFlag($cohortChatIds, $flag);
    }

    /**
     * @param  list<int>  $cohortChatIds
     */
    private function countMilestoneInPeriod(array $cohortChatIds, string $milestone, Carbon $from, Carbon $to): int
    {
        if ($cohortChatIds === [] || ! Schema::hasTable('sales_milestones')) {
            return 0;
        }

        return (int) SalesMilestone::query()
            ->whereIn('chat_id', $cohortChatIds)
            ->where('milestone', $milestone)
            ->whereBetween('occurred_at', [$from, $to])
            ->distinct()
            ->count('chat_id');
    }

    /**
     * @param  list<int>  $cohortChatIds
     */
    private function deferralRecoveryCount(array $cohortChatIds, Carbon $from, Carbon $to): int
    {
        if ($cohortChatIds === [] || ! Schema::hasTable('sales_milestones')) {
            return 0;
        }

        $deferralChatIds = SalesMilestone::query()
            ->whereIn('chat_id', $cohortChatIds)
            ->where('milestone', SalesMilestone::MILESTONE_DEFERRAL)
            ->whereBetween('occurred_at', [$from, $to])
            ->pluck('chat_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($deferralChatIds === []) {
            return 0;
        }

        return (int) SalesMilestone::query()
            ->whereIn('chat_id', $deferralChatIds)
            ->where('milestone', SalesMilestone::MILESTONE_RE_ENGAGED)
            ->whereBetween('occurred_at', [$from, $to])
            ->distinct()
            ->count('chat_id');
    }

    /**
     * @param  list<int>  $companyIds
     * @return array{top_objections: list<array<string, mixed>>, top_winning_responses: list<array<string, mixed>>, top_losing_responses: list<array<string, mixed>>}
     */
    private function aggregateObjectionIntelligence(array $companyIds): array
    {
        $merged = [
            'top_objections' => [],
            'top_winning_responses' => [],
            'top_losing_responses' => [],
        ];

        foreach ($companyIds as $companyId) {
            $payload = $this->objectionIntel->buildForCompany($companyId);
            $merged['top_objections'] = array_merge($merged['top_objections'], $payload['top_objections']);
            $merged['top_winning_responses'] = array_merge($merged['top_winning_responses'], $payload['top_winning_responses']);
            $merged['top_losing_responses'] = array_merge($merged['top_losing_responses'], $payload['top_losing_responses']);
        }

        usort($merged['top_objections'], static fn (array $a, array $b): int => ($b['frequency'] ?? 0) <=> ($a['frequency'] ?? 0));
        $merged['top_objections'] = array_slice($merged['top_objections'], 0, 10);

        return $merged;
    }

    /**
     * @param  list<int>  $cohortChatIds
     */
    private function countSalesStateFlag(array $cohortChatIds, string $flag): int
    {
        if ($cohortChatIds === []) {
            return 0;
        }

        return Chat::query()
            ->withoutGlobalScope('tenant')
            ->whereIn('id', $cohortChatIds)
            ->where("sales_state->{$flag}", true)
            ->count();
    }

    /**
     * @param  list<int>  $companyIds
     * @param  list<int>  $cohortChatIds
     */
    private function proposalChatCount(array $companyIds, array $cohortChatIds, Carbon $from, Carbon $to): int
    {
        if ($cohortChatIds === []) {
            return 0;
        }

        $proposalStageIds = $this->proposalStageIds($companyIds);
        $chatIds = collect($cohortChatIds);

        $presentOfferCount = Chat::query()
            ->withoutGlobalScope('tenant')
            ->whereIn('id', $cohortChatIds)
            ->where('sales_state->next_action', ChatSalesStateService::NA_PRESENT_OFFER)
            ->pluck('id');

        $stageChatIds = collect();
        if ($proposalStageIds !== []) {
            $stageChatIds = Chat::query()
                ->withoutGlobalScope('tenant')
                ->whereIn('id', $cohortChatIds)
                ->whereIn('funnel_stage_id', $proposalStageIds)
                ->pluck('id');

            $transitionChatIds = ChatFunnelTransition::query()
                ->whereIn('chat_id', $cohortChatIds)
                ->whereIn('to_stage_id', $proposalStageIds)
                ->whereBetween('created_at', [$from, $to])
                ->distinct()
                ->pluck('chat_id');

            $stageChatIds = $stageChatIds->merge($transitionChatIds);
        }

        return $chatIds
            ->merge($presentOfferCount)
            ->merge($stageChatIds)
            ->unique()
            ->count();
    }

    /**
     * @param  list<int>  $companyIds
     * @return list<int>
     */
    private function proposalStageIds(array $companyIds): array
    {
        if ($companyIds === []) {
            return [];
        }

        return FunnelStage::query()
            ->whereHas('funnel', static fn (Builder $q) => $q->whereIn('company_id', $companyIds))
            ->get(['id', 'name'])
            ->filter(fn (FunnelStage $stage): bool => $this->isProposalStageName((string) $stage->name))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    private function isProposalStageName(string $name): bool
    {
        $lower = mb_strtolower($name);

        foreach (self::PROPOSAL_STAGE_PATTERNS as $pattern) {
            if (str_contains($lower, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<int>  $companyIds
     * @param  list<int>  $cohortChatIds
     */
    private function meetingBookingChatCount(array $companyIds, array $cohortChatIds, Carbon $from, Carbon $to): int
    {
        if ($cohortChatIds === [] || ! Schema::hasTable('ai_orchestrator_actions')) {
            return 0;
        }

        return (int) AiOrchestratorAction::query()
            ->whereIn('company_id', $companyIds)
            ->whereIn('chat_id', $cohortChatIds)
            ->where('type', 'create_appointment')
            ->where('status', AiOrchestratorAction::STATUS_DONE)
            ->whereBetween('created_at', [$from, $to])
            ->distinct()
            ->count('chat_id');
    }

    /**
     * @param  list<int>  $companyIds
     */
    private function closedDealsQuery(array $companyIds, Carbon $from, Carbon $to): Builder
    {
        return DealOutcome::query()
            ->whereIn('company_id', $companyIds)
            ->whereBetween('closed_at', [$from, $to]);
    }

    /**
     * @param  list<int>  $companyIds
     * @return list<array{reason: string, count: int, percent: float}>
     */
    private function lostReasonDistribution(array $companyIds, Carbon $from, Carbon $to): array
    {
        if ($companyIds === []) {
            return [];
        }

        $losses = DealOutcome::query()
            ->whereIn('company_id', $companyIds)
            ->where('won', false)
            ->whereBetween('closed_at', [$from, $to])
            ->get(['reason']);

        if ($losses->isEmpty()) {
            return [];
        }

        $grouped = $losses
            ->groupBy(static function (DealOutcome $row): string {
                $reason = trim((string) ($row->reason ?? ''));

                return $reason !== '' ? $reason : 'не указано';
            })
            ->map(static fn ($group, string $reason): array => [
                'reason' => $reason,
                'count' => $group->count(),
            ])
            ->sortByDesc('count')
            ->values();

        $sum = (int) $grouped->sum('count');
        if ($sum === 0) {
            return [];
        }

        return $grouped->map(static fn (array $row): array => [
            'reason' => $row['reason'],
            'count' => (int) $row['count'],
            'percent' => round((int) $row['count'] * 100 / $sum, 1),
        ])->all();
    }

    /**
     * @param  list<int>  $companyIds
     * @return list<array{grade: string, won: int, total: int, percent: float|null}>
     */
    private function winRateByGrade(array $companyIds, Carbon $from, Carbon $to): array
    {
        if ($companyIds === []) {
            return [];
        }

        $rows = DealOutcome::query()
            ->whereIn('company_id', $companyIds)
            ->whereBetween('closed_at', [$from, $to])
            ->whereNotNull('lead_grade')
            ->where('lead_grade', '!=', '')
            ->selectRaw('lead_grade, SUM(CASE WHEN won = 1 THEN 1 ELSE 0 END) as won_count, COUNT(*) as total_count')
            ->groupBy('lead_grade')
            ->orderBy('lead_grade')
            ->get();

        return $rows->map(static function ($row): array {
            $total = (int) $row->total_count;
            $won = (int) $row->won_count;

            return [
                'grade' => (string) $row->lead_grade,
                'won' => $won,
                'total' => $total,
                'percent' => $total > 0 ? round($won * 100 / $total, 1) : null,
            ];
        })->values()->all();
    }

    /**
     * @param  list<int>  $companyIds
     */
    private function followUpsSentCount(array $companyIds, Carbon $from, Carbon $to, ?string $purpose = null): int
    {
        if ($companyIds === [] || ! Schema::hasTable('scheduled_messages')) {
            return 0;
        }

        $purposes = $purpose !== null
            ? [$purpose]
            : [
                ScheduledMessage::PURPOSE_FUNNEL_FOLLOW_UP,
                ScheduledMessage::PURPOSE_NURTURE_FOLLOW_UP,
            ];

        return ScheduledMessage::query()
            ->where('status', ScheduledMessage::STATUS_SENT)
            ->whereIn('purpose', $purposes)
            ->whereHas('chat', static fn (Builder $q) => $q
                ->withoutGlobalScope('tenant')
                ->whereIn('company_id', $companyIds))
            ->whereRaw('COALESCE(updated_at, scheduled_at) >= ?', [$from])
            ->whereRaw('COALESCE(updated_at, scheduled_at) <= ?', [$to])
            ->count();
    }

    /**
     * @param  list<int>  $companyIds
     */
    private function followUpResponseCount(array $companyIds, Carbon $from, Carbon $to, ?string $purpose = null): int
    {
        if ($companyIds === [] || ! Schema::hasTable('scheduled_messages')) {
            return 0;
        }

        $purposes = $purpose !== null
            ? [$purpose]
            : [
                ScheduledMessage::PURPOSE_FUNNEL_FOLLOW_UP,
                ScheduledMessage::PURPOSE_NURTURE_FOLLOW_UP,
            ];

        $followUps = ScheduledMessage::query()
            ->with(['chat:id,company_id'])
            ->where('status', ScheduledMessage::STATUS_SENT)
            ->whereIn('purpose', $purposes)
            ->whereHas('chat', static fn (Builder $q) => $q
                ->withoutGlobalScope('tenant')
                ->whereIn('company_id', $companyIds))
            ->whereRaw('COALESCE(updated_at, scheduled_at) >= ?', [$from])
            ->whereRaw('COALESCE(updated_at, scheduled_at) <= ?', [$to])
            ->get(['id', 'chat_id', 'updated_at', 'scheduled_at']);

        $responses = 0;
        foreach ($followUps as $followUp) {
            $sentAt = $followUp->updated_at ?? $followUp->scheduled_at;
            if ($sentAt === null) {
                continue;
            }

            $deadline = $sentAt->copy()->addHours(self::FOLLOW_UP_RESPONSE_WINDOW_HOURS);

            $hasReply = Message::query()
                ->where('chat_id', $followUp->chat_id)
                ->where('direction', 'inbound')
                ->whereRaw('COALESCE(message_timestamp, created_at) > ?', [$sentAt])
                ->whereRaw('COALESCE(message_timestamp, created_at) <= ?', [$deadline])
                ->exists();

            if ($hasReply) {
                $responses++;
            }
        }

        return $responses;
    }

    /**
     * @param  list<int>  $companyIds
     * @return list<array<string, mixed>>
     */
    private function buildByCompanyBreakdown(User $superAdmin, array $companyIds, Carbon $from, Carbon $to): array
    {
        $companies = Company::query()
            ->whereIn('id', $companyIds)
            ->orderBy('name')
            ->get(['id', 'name', 'slug']);

        $rows = [];
        foreach ($companies as $company) {
            $payload = $this->build($superAdmin, $from, $to, (int) $company->id);
            $closeKpi = collect($payload['kpis'])->firstWhere('key', 'close_rate');

            $rows[] = [
                'company_id' => $company->id,
                'company_name' => $company->name,
                'company_slug' => $company->slug,
                'cohort_size' => $payload['summary']['cohort_size'],
                'closed_deals' => $payload['summary']['closed_deals'],
                'qualification_rate' => collect($payload['kpis'])->firstWhere('key', 'qualification_rate')['percent'] ?? null,
                'budget_capture_rate' => collect($payload['kpis'])->firstWhere('key', 'budget_capture_rate')['percent'] ?? null,
                'close_rate' => $closeKpi['percent'] ?? null,
                'meeting_booking_rate' => collect($payload['kpis'])->firstWhere('key', 'meeting_booking_rate')['percent'] ?? null,
            ];
        }

        usort($rows, static fn (array $a, array $b): int => ($b['cohort_size'] ?? 0) <=> ($a['cohort_size'] ?? 0));

        return $rows;
    }

    /**
     * @return array{key: string, label: string, percent: float|null, numerator: int, denominator: int, sufficient_data: bool}
     */
    private function kpi(
        string $key,
        string $label,
        int $numerator,
        int $denominator,
        bool $sufficientData,
    ): array {
        return [
            'key' => $key,
            'label' => $label,
            'percent' => $sufficientData && $denominator > 0
                ? round($numerator * 100 / $denominator, 1)
                : null,
            'numerator' => $numerator,
            'denominator' => $denominator,
            'sufficient_data' => $sufficientData,
        ];
    }
}
