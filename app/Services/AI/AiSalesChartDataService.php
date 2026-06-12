<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\Chat;
use App\Models\DealOutcome;
use App\Models\Message;
use App\Models\SalesMilestone;
use App\Models\WinProbabilityModel;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\Schema;

final class AiSalesChartDataService
{
    /**
     * @param  list<int>  $companyIds
     * @param  list<int>  $cohortChatIds
     * @param  list<array{key: string, label: string, percent: float|null, numerator: int, denominator: int, sufficient_data: bool}>  $kpis
     * @param  list<array{reason: string, count: int, percent: float}>  $lostReasons
     * @param  list<array{grade: string, won: int, total: int, percent: float|null}>  $winRateByGrade
     * @param  array{top_objections: list<array<string, mixed>>, top_winning_responses: list<array<string, mixed>>, top_losing_responses: list<array<string, mixed>>}  $objectionIntelligence
     * @param  list<array<string, mixed>>  $experiments
     * @param  list<array<string, mixed>>  $byCompany
     * @return array<string, mixed>
     */
    public function build(
        array $companyIds,
        array $cohortChatIds,
        Carbon $from,
        Carbon $to,
        array $kpis,
        array $lostReasons,
        array $winRateByGrade,
        array $objectionIntelligence,
        array $experiments,
        array $byCompany,
        ?int $companyId,
    ): array {
        $labels = $this->dayLabels($from, $to);

        return [
            'funnel' => $this->funnelChart($kpis, $cohortChatIds),
            'outcomes_daily' => $this->outcomesDaily($companyIds, $from, $to, $labels),
            'cohort_daily' => $this->cohortDaily($companyIds, $from, $to, $labels),
            'milestones_daily' => $this->milestonesDaily($cohortChatIds, $from, $to, $labels),
            'lost_reasons' => $this->lostReasonsChart($lostReasons),
            'win_rate_by_grade' => $this->winRateByGradeChart($winRateByGrade),
            'objections' => $this->objectionsChart($objectionIntelligence),
            'experiments' => $this->experimentsChart($experiments),
            'by_company' => $this->byCompanyChart($byCompany),
            'win_prob_calibration' => $this->winProbCalibrationChart($companyId),
        ];
    }

    /**
     * @return list<string>
     */
    private function dayLabels(Carbon $from, Carbon $to): array
    {
        $labels = [];
        foreach (CarbonPeriod::create($from->copy()->startOfDay(), '1 day', $to->copy()->startOfDay()) as $day) {
            $labels[] = $day->format('Y-m-d');
        }

        return $labels;
    }

    /**
     * @param  list<array{key: string, percent: float|null, numerator: int, denominator: int, sufficient_data: bool}>  $kpis
     * @param  list<int>  $cohortChatIds
     * @return array{stages: list<array{name: string, key: string, value: int, percent: float|null}>}
     */
    private function funnelChart(array $kpis, array $cohortChatIds): array
    {
        $cohortSize = count($cohortChatIds);
        $kpiMap = [];
        foreach ($kpis as $kpi) {
            $kpiMap[$kpi['key']] = $kpi;
        }

        $stageKeys = [
            ['key' => 'qualification_rate', 'name' => 'Qualification'],
            ['key' => 'budget_capture_rate', 'name' => 'Budget'],
            ['key' => 'proposal_rate', 'name' => 'Proposal'],
            ['key' => 'meeting_booking_rate', 'name' => 'Meeting'],
            ['key' => 'close_rate', 'name' => 'Close'],
        ];

        $stages = [];
        foreach ($stageKeys as $stage) {
            $kpi = $kpiMap[$stage['key']] ?? null;
            $stages[] = [
                'name' => $stage['name'],
                'key' => $stage['key'],
                'value' => $kpi !== null ? (int) $kpi['numerator'] : 0,
                'percent' => $kpi['percent'] ?? null,
                'denominator' => $kpi['denominator'] ?? $cohortSize,
            ];
        }

        return ['stages' => $stages, 'cohort_size' => $cohortSize];
    }

    /**
     * @param  list<int>  $companyIds
     * @param  list<string>  $labels
     * @return array{labels: list<string>, won: list<int>, lost: list<int>}
     */
    private function outcomesDaily(array $companyIds, Carbon $from, Carbon $to, array $labels): array
    {
        $won = array_fill_keys($labels, 0);
        $lost = array_fill_keys($labels, 0);

        if ($companyIds === [] || ! Schema::hasTable('deal_outcomes')) {
            return [
                'labels' => $labels,
                'won' => array_values($won),
                'lost' => array_values($lost),
            ];
        }

        $rows = DealOutcome::query()
            ->whereIn('company_id', $companyIds)
            ->whereBetween('closed_at', [$from, $to])
            ->get(['won', 'closed_at']);

        foreach ($rows as $row) {
            if ($row->closed_at === null) {
                continue;
            }
            $day = $row->closed_at->format('Y-m-d');
            if (! isset($won[$day])) {
                continue;
            }
            if ($row->won) {
                $won[$day]++;
            } else {
                $lost[$day]++;
            }
        }

        return [
            'labels' => $labels,
            'won' => array_values($won),
            'lost' => array_values($lost),
        ];
    }

    /**
     * @param  list<int>  $companyIds
     * @param  list<string>  $labels
     * @return array{labels: list<string>, counts: list<int>}
     */
    private function cohortDaily(array $companyIds, Carbon $from, Carbon $to, array $labels): array
    {
        $counts = array_fill_keys($labels, 0);

        if ($companyIds === []) {
            return ['labels' => $labels, 'counts' => array_values($counts)];
        }

        $chatIds = Chat::query()
            ->withoutGlobalScope('tenant')
            ->whereIn('company_id', $companyIds)
            ->where('is_group', false)
            ->whereHas('messages', static function ($query) use ($from, $to): void {
                $query->where('direction', 'inbound')
                    ->whereRaw('COALESCE(message_timestamp, created_at) >= ?', [$from])
                    ->whereRaw('COALESCE(message_timestamp, created_at) <= ?', [$to]);
            })
            ->pluck('id');

        if ($chatIds->isEmpty()) {
            return ['labels' => $labels, 'counts' => array_values($counts)];
        }

        $firstInbound = Message::query()
            ->whereIn('chat_id', $chatIds)
            ->where('direction', 'inbound')
            ->selectRaw('chat_id, MIN(COALESCE(message_timestamp, created_at)) as first_at')
            ->groupBy('chat_id')
            ->get();

        foreach ($firstInbound as $row) {
            if ($row->first_at === null) {
                continue;
            }
            $day = Carbon::parse((string) $row->first_at)->format('Y-m-d');
            if (isset($counts[$day])) {
                $counts[$day]++;
            }
        }

        return ['labels' => $labels, 'counts' => array_values($counts)];
    }

    /**
     * @param  list<int>  $cohortChatIds
     * @param  list<string>  $labels
     * @return array{labels: list<string>, qualified: list<int>, meeting_booked: list<int>, closed_won: list<int>}
     */
    private function milestonesDaily(array $cohortChatIds, Carbon $from, Carbon $to, array $labels): array
    {
        $qualified = array_fill_keys($labels, 0);
        $meeting = array_fill_keys($labels, 0);
        $closedWon = array_fill_keys($labels, 0);

        if ($cohortChatIds === [] || ! Schema::hasTable('sales_milestones')) {
            return [
                'labels' => $labels,
                'qualified' => array_values($qualified),
                'meeting_booked' => array_values($meeting),
                'closed_won' => array_values($closedWon),
            ];
        }

        $milestones = [
            SalesMilestone::MILESTONE_QUALIFIED => &$qualified,
            SalesMilestone::MILESTONE_MEETING_BOOKED => &$meeting,
            SalesMilestone::MILESTONE_CLOSED_WON => &$closedWon,
        ];

        foreach ($milestones as $milestone => &$bucket) {
            $rows = SalesMilestone::query()
                ->whereIn('chat_id', $cohortChatIds)
                ->where('milestone', $milestone)
                ->whereBetween('occurred_at', [$from, $to])
                ->get(['occurred_at', 'chat_id']);

            foreach ($rows as $row) {
                if ($row->occurred_at === null) {
                    continue;
                }
                $day = $row->occurred_at->format('Y-m-d');
                if (isset($bucket[$day])) {
                    $bucket[$day]++;
                }
            }
        }

        return [
            'labels' => $labels,
            'qualified' => array_values($qualified),
            'meeting_booked' => array_values($meeting),
            'closed_won' => array_values($closedWon),
        ];
    }

    /**
     * @param  list<array{reason: string, count: int, percent: float}>  $lostReasons
     * @return array{labels: list<string>, values: list<int>, percents: list<float>}
     */
    private function lostReasonsChart(array $lostReasons): array
    {
        return [
            'labels' => array_column($lostReasons, 'reason'),
            'values' => array_map('intval', array_column($lostReasons, 'count')),
            'percents' => array_map('floatval', array_column($lostReasons, 'percent')),
        ];
    }

    /**
     * @param  list<array{grade: string, won: int, total: int, percent: float|null}>  $winRateByGrade
     * @return array{grades: list<string>, won: list<int>, total: list<int>, rates: list<float|null>}
     */
    private function winRateByGradeChart(array $winRateByGrade): array
    {
        return [
            'grades' => array_column($winRateByGrade, 'grade'),
            'won' => array_map('intval', array_column($winRateByGrade, 'won')),
            'total' => array_map('intval', array_column($winRateByGrade, 'total')),
            'rates' => array_column($winRateByGrade, 'percent'),
        ];
    }

    /**
     * @param  array{top_objections: list<array<string, mixed>>}  $objectionIntelligence
     * @return array{labels: list<string>, frequencies: list<int>, win_rates: list<float|null>}
     */
    private function objectionsChart(array $objectionIntelligence): array
    {
        $rows = $objectionIntelligence['top_objections'] ?? [];

        return [
            'labels' => array_map(static fn (array $r): string => (string) ($r['label'] ?? ''), $rows),
            'frequencies' => array_map(static fn (array $r): int => (int) ($r['frequency'] ?? 0), $rows),
            'win_rates' => array_map(static fn (array $r) => isset($r['win_rate']) ? (float) $r['win_rate'] : null, $rows),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $experiments
     * @return array{labels: list<string>, replies: list<int>, qualified: list<int>, close_rates: list<float|null>}
     */
    private function experimentsChart(array $experiments): array
    {
        $labels = [];
        $replies = [];
        $qualified = [];
        $closeRates = [];

        foreach ($experiments as $row) {
            $labels[] = trim((string) ($row['experiment_name'] ?? '')).' · '.(string) ($row['variant_key'] ?? '');
            $replies[] = (int) ($row['replies'] ?? 0);
            $qualified[] = (int) ($row['qualified'] ?? 0);
            $closeRates[] = isset($row['close_rate']) ? (float) $row['close_rate'] : null;
        }

        return [
            'labels' => $labels,
            'replies' => $replies,
            'qualified' => $qualified,
            'close_rates' => $closeRates,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $byCompany
     * @return array{labels: list<string>, cohort: list<int>, close_rates: list<float|null>}
     */
    public function formatByCompanyChart(array $byCompany): array
    {
        return $this->byCompanyChart($byCompany);
    }

    /**
     * @param  list<array<string, mixed>>  $byCompany
     * @return array{labels: list<string>, cohort: list<int>, close_rates: list<float|null>}
     */
    private function byCompanyChart(array $byCompany): array
    {
        $sorted = collect($byCompany)
            ->sortByDesc(static fn (array $row): float => (float) ($row['close_rate'] ?? 0))
            ->take(10)
            ->values();

        return [
            'labels' => $sorted->pluck('company_name')->map(fn ($n) => (string) $n)->all(),
            'cohort' => $sorted->pluck('cohort_size')->map(fn ($n) => (int) $n)->all(),
            'close_rates' => $sorted->pluck('close_rate')->map(fn ($n) => $n !== null ? (float) $n : null)->all(),
        ];
    }

    /**
     * @return array{labels: list<string>, predicted: list<float>, actual: list<float>}|null
     */
    private function winProbCalibrationChart(?int $companyId): ?array
    {
        if ($companyId === null || ! Schema::hasTable('win_probability_models')) {
            return null;
        }

        $model = WinProbabilityModel::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->orderByDesc('version')
            ->first();

        if ($model === null || ! is_array($model->metrics)) {
            return null;
        }

        $bins = $model->metrics['calibration_bins'] ?? null;
        if (! is_array($bins) || $bins === []) {
            return null;
        }

        $labels = [];
        $predicted = [];
        $actual = [];

        foreach ($bins as $bin) {
            if (! is_array($bin)) {
                continue;
            }
            $labels[] = (string) ($bin['label'] ?? '');
            $predicted[] = (float) ($bin['predicted'] ?? 0);
            $actual[] = (float) ($bin['actual'] ?? 0);
        }

        if ($labels === []) {
            return null;
        }

        return compact('labels', 'predicted', 'actual');
    }
}
