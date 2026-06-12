<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\DealOutcome;
use App\Models\ObjectionCluster;
use App\Models\ObjectionResponse;
use Illuminate\Support\Facades\Schema;

final class ObjectionIntelligenceService
{
    /** @var array<string, list<string>> */
    private const CLUSTER_KEYWORDS = [
        'price' => ['цена', 'дорог', 'дешев', 'скид', 'бюджет'],
        'timing' => ['срок', 'позже', 'подума', 'не сейчас', 'через'],
        'competitor' => ['конкур', 'другой', 'альтернатив'],
        'trust' => ['не вер', 'сомнева', 'гарант', 'обман'],
    ];

    /**
     * @return array{
     *     top_objections: list<array{label: string, frequency: int, win_rate: float|null}>,
     *     top_winning_responses: list<array{text: string, win_count: int}>,
     *     top_losing_responses: list<array{text: string, loss_count: int}>
     * }
     */
    public function buildForCompany(int $companyId): array
    {
        if (! Schema::hasTable('objection_clusters')) {
            return [
                'top_objections' => [],
                'top_winning_responses' => [],
                'top_losing_responses' => [],
            ];
        }

        $this->syncClustersFromOutcomes($companyId);

        $topObjections = ObjectionCluster::query()
            ->where('company_id', $companyId)
            ->orderByDesc('frequency')
            ->limit(10)
            ->get()
            ->map(static fn (ObjectionCluster $row): array => [
                'label' => $row->label,
                'frequency' => (int) $row->frequency,
                'win_rate' => $row->win_rate_after_handling !== null ? (float) $row->win_rate_after_handling : null,
            ])
            ->values()
            ->all();

        $topWinning = ObjectionResponse::query()
            ->whereHas('cluster', static fn ($q) => $q->where('company_id', $companyId))
            ->orderByDesc('win_count')
            ->limit(5)
            ->get(['response_text', 'win_count'])
            ->map(static fn (ObjectionResponse $row): array => [
                'text' => mb_substr((string) $row->response_text, 0, 200),
                'win_count' => (int) $row->win_count,
            ])
            ->values()
            ->all();

        $topLosing = ObjectionResponse::query()
            ->whereHas('cluster', static fn ($q) => $q->where('company_id', $companyId))
            ->orderByDesc('loss_count')
            ->limit(5)
            ->get(['response_text', 'loss_count'])
            ->map(static fn (ObjectionResponse $row): array => [
                'text' => mb_substr((string) $row->response_text, 0, 200),
                'loss_count' => (int) $row->loss_count,
            ])
            ->values()
            ->all();

        return [
            'top_objections' => $topObjections,
            'top_winning_responses' => $topWinning,
            'top_losing_responses' => $topLosing,
        ];
    }

    public function syncClustersFromOutcomes(int $companyId): void
    {
        if (! Schema::hasTable('deal_outcomes')) {
            return;
        }

        $outcomes = DealOutcome::query()
            ->where('company_id', $companyId)
            ->whereNotNull('objections_at_close')
            ->get(['won', 'objections_at_close', 'reason']);

        $counts = [];
        $wins = [];

        foreach ($outcomes as $outcome) {
            $label = $this->classify((string) ($outcome->objections_at_close ?: $outcome->reason ?: ''));
            $counts[$label] = ($counts[$label] ?? 0) + 1;
            if ($outcome->won) {
                $wins[$label] = ($wins[$label] ?? 0) + 1;
            }
        }

        foreach ($counts as $label => $frequency) {
            $winRate = $frequency > 0 ? round(($wins[$label] ?? 0) * 100 / $frequency, 1) : null;

            ObjectionCluster::query()->updateOrCreate(
                ['company_id' => $companyId, 'label' => $label],
                [
                    'frequency' => $frequency,
                    'win_rate_after_handling' => $winRate,
                ],
            );
        }
    }

    private function classify(string $text): string
    {
        $lower = mb_strtolower(trim($text));
        if ($lower === '') {
            return 'unknown';
        }

        foreach (self::CLUSTER_KEYWORDS as $label => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($lower, $keyword)) {
                    return $label;
                }
            }
        }

        return 'other';
    }

    /**
     * @return list<string>
     */
    public function promptBlockForCompany(int $companyId): array
    {
        $data = $this->buildForCompany($companyId);
        $lines = [];

        foreach ($data['top_objections'] as $row) {
            if ($row['label'] === 'unknown') {
                continue;
            }
            $lines[] = "- {$row['label']}: {$row['frequency']} cases"
                .($row['win_rate'] !== null ? ", win after handling {$row['win_rate']}%" : '');
        }

        return $lines;
    }
}
