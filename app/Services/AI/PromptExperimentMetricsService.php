<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\AiExperiment;
use App\Models\AiResponseLog;
use App\Models\DealOutcome;
use App\Models\SalesMilestone;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class PromptExperimentMetricsService
{
    /**
     * @param list<int> $companyIds
     * @return list<array<string, mixed>>
     */
    public function forCompanyIds(array $companyIds, Carbon $from, Carbon $to): array
    {
        if ($companyIds === [] || ! Schema::hasTable('ai_experiments')) {
            return [];
        }

        $experiments = AiExperiment::query()
            ->with('variants')
            ->where(function ($query) use ($companyIds): void {
                $query->whereNull('company_id')->orWhereIn('company_id', $companyIds);
            })
            ->get();

        $results = [];
        foreach ($experiments as $experiment) {
            foreach ($experiment->variants as $variant) {
                $stats = $this->variantStats($experiment, $variant->key, $companyIds, $from, $to);
                $results[] = [
                    'experiment_id' => $experiment->id,
                    'experiment_name' => $experiment->name,
                    'variant_key' => $variant->key,
                    'is_control' => (bool) $variant->is_control,
                    ...$stats,
                ];
            }
        }

        return $results;
    }

    /**
     * @param list<int> $companyIds
     * @return array{replies: int, qualified: int, closed_won: int, close_rate: float|null}
     */
    private function variantStats(
        AiExperiment $experiment,
        string $variantKey,
        array $companyIds,
        Carbon $from,
        Carbon $to,
    ): array {
        if (! Schema::hasTable('ai_response_logs')) {
            return ['replies' => 0, 'qualified' => 0, 'closed_won' => 0, 'close_rate' => null];
        }

        $chatIds = AiResponseLog::query()
            ->whereIn('company_id', $companyIds)
            ->whereBetween('created_at', [$from, $to])
            ->where('metadata->experiment_id', $experiment->id)
            ->where('metadata->variant_key', $variantKey)
            ->distinct()
            ->pluck('chat_id');

        $replies = (int) $chatIds->count();
        if ($replies === 0) {
            return ['replies' => 0, 'qualified' => 0, 'closed_won' => 0, 'close_rate' => null];
        }

        $qualified = 0;
        if (Schema::hasTable('sales_milestones')) {
            $qualified = (int) SalesMilestone::query()
                ->whereIn('chat_id', $chatIds)
                ->where('milestone', 'qualified')
                ->distinct('chat_id')
                ->count('chat_id');
        }

        $closedWon = 0;
        if (Schema::hasTable('deal_outcomes')) {
            $closedWon = (int) DealOutcome::query()
                ->whereIn('chat_id', $chatIds)
                ->where('won', true)
                ->whereBetween('closed_at', [$from, $to])
                ->count();
        }

        $closedTotal = Schema::hasTable('deal_outcomes')
            ? (int) DealOutcome::query()
                ->whereIn('chat_id', $chatIds)
                ->whereBetween('closed_at', [$from, $to])
                ->count()
            : 0;

        return [
            'replies' => $replies,
            'qualified' => $qualified,
            'closed_won' => $closedWon,
            'close_rate' => $closedTotal > 0 ? round($closedWon / $closedTotal * 100, 1) : null,
        ];
    }
}
