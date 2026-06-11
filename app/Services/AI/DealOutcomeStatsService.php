<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\DealOutcome;
use Illuminate\Support\Facades\Cache;

final class DealOutcomeStatsService
{
    private const MIN_OUTCOMES = 10;

    private const CACHE_TTL_SECONDS = 86400;

    /**
     * Human-readable prompt block for tenant-level win/loss insights.
     */
    public function promptBlock(int $companyId): string
    {
        $stats = $this->stats($companyId);
        if ($stats === null) {
            return '';
        }

        $lines = ['## Инсайты по сделкам (ваша компания)'];

        if ($stats['top_loss_reason'] !== null) {
            $lines[] = "- Частая причина отказа: «{$stats['top_loss_reason']}». Акцент на value, не сбрасывай цену сразу.";
        }
        if ($stats['top_win_reason'] !== null) {
            $lines[] = "- Частый фактор успеха: «{$stats['top_win_reason']}».";
        }
        if ($stats['grade_a_win_rate'] !== null) {
            $lines[] = "- Конверсия лидов grade A: {$stats['grade_a_win_rate']}%.";
        }

        return count($lines) > 1 ? implode("\n", $lines) : '';
    }

    /**
     * @return array{top_loss_reason: string|null, top_win_reason: string|null, grade_a_win_rate: string|null}|null
     */
    public function stats(int $companyId): ?array
    {
        return Cache::remember(
            "deal_outcome_stats:{$companyId}",
            self::CACHE_TTL_SECONDS,
            fn (): ?array => $this->computeStats($companyId),
        );
    }

    /**
     * @return array{top_loss_reason: string|null, top_win_reason: string|null, grade_a_win_rate: string|null}|null
     */
    private function computeStats(int $companyId): ?array
    {
        $total = DealOutcome::query()->where('company_id', $companyId)->count();
        if ($total < self::MIN_OUTCOMES) {
            return null;
        }

        $topLoss = DealOutcome::query()
            ->where('company_id', $companyId)
            ->where('won', false)
            ->whereNotNull('reason')
            ->selectRaw('reason, COUNT(*) as cnt')
            ->groupBy('reason')
            ->orderByDesc('cnt')
            ->value('reason');

        $topWin = DealOutcome::query()
            ->where('company_id', $companyId)
            ->where('won', true)
            ->whereNotNull('reason')
            ->selectRaw('reason, COUNT(*) as cnt')
            ->groupBy('reason')
            ->orderByDesc('cnt')
            ->value('reason');

        $gradeATotal = DealOutcome::query()
            ->where('company_id', $companyId)
            ->where('lead_grade', 'A')
            ->count();

        $gradeAWon = DealOutcome::query()
            ->where('company_id', $companyId)
            ->where('lead_grade', 'A')
            ->where('won', true)
            ->count();

        $gradeAWinRate = $gradeATotal > 0
            ? (string) round(100 * $gradeAWon / $gradeATotal)
            : null;

        return [
            'top_loss_reason' => is_string($topLoss) ? $topLoss : null,
            'top_win_reason' => is_string($topWin) ? $topWin : null,
            'grade_a_win_rate' => $gradeAWinRate,
        ];
    }

    public function forgetCache(int $companyId): void
    {
        Cache::forget("deal_outcome_stats:{$companyId}");
    }
}
