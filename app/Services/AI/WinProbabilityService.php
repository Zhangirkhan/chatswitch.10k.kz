<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\Chat;
use App\Models\DealOutcome;
use App\Models\WinProbabilityScore;
use App\Support\AiFeatureFlags;
use Illuminate\Support\Facades\Schema;

final class WinProbabilityService
{
    public function __construct(
        private readonly ChatSalesStateService $salesStateService,
    ) {}

    /**
     * @return array{
     *     win_probability: int,
     *     risk_factors: list<string>,
     *     recommended_action: string
     * }
     */
    public function compute(Chat $chat): array
    {
        $state = $this->salesStateService->freshState($chat);
        $stageBase = $this->stageBaseProbability($chat);
        $scoreNorm = isset($state['score']) ? min(100, max(0, (int) $state['score'])) / 100 : 0.3;

        $bantFields = ['budget_known', 'requirements_known', 'timeline_known', 'decision_maker_known'];
        $bantCount = count(array_filter($bantFields, static fn (string $f): bool => ($state[$f] ?? false) === true));
        $bantBonus = $bantCount * 5;

        $objections = is_array($state['objections_open'] ?? null) ? count($state['objections_open']) : 0;
        $objectionPenalty = min(20, $objections * 7);

        $deferralPenalty = ($state['deferral_detected'] ?? false) === true ? 12 : 0;

        $industryBonus = $this->industryHistoricalBonus((int) $chat->company_id);

        $probability = (int) round(min(95, max(5,
            $stageBase
            + ($scoreNorm * 25)
            + $bantBonus
            - $objectionPenalty
            - $deferralPenalty
            + $industryBonus
        )));

        $riskFactors = $this->riskFactors($state);
        $recommendedAction = (string) ($state['next_action'] ?? ChatSalesStateService::NA_QUALIFY);

        $result = [
            'win_probability' => $probability,
            'risk_factors' => $riskFactors,
            'recommended_action' => $recommendedAction,
        ];

        if (AiFeatureFlags::enabled(AiFeatureFlags::SALES_STATE, (int) $chat->company_id)
            && Schema::hasTable('win_probability_scores')
        ) {
            WinProbabilityScore::query()->create([
                'company_id' => (int) $chat->company_id,
                'chat_id' => (int) $chat->id,
                'probability' => $probability,
                'risk_factors' => $riskFactors,
                'recommended_action' => $recommendedAction,
                'inputs_snapshot' => [
                    'sales_state' => $state,
                    'funnel_stage_id' => $chat->funnel_stage_id,
                ],
                'computed_at' => now(),
            ]);
        }

        return $result;
    }

    /**
     * @return array{win_probability: int, risk_factors: list<string>, recommended_action: string}|null
     */
    public function latestForChat(Chat $chat): ?array
    {
        if (! Schema::hasTable('win_probability_scores')) {
            return null;
        }

        $row = WinProbabilityScore::query()
            ->where('chat_id', $chat->id)
            ->orderByDesc('computed_at')
            ->first();

        if ($row === null) {
            return $this->compute($chat);
        }

        return [
            'win_probability' => (int) $row->probability,
            'risk_factors' => is_array($row->risk_factors) ? $row->risk_factors : [],
            'recommended_action' => (string) ($row->recommended_action ?? ChatSalesStateService::NA_QUALIFY),
        ];
    }

    private function stageBaseProbability(Chat $chat): int
    {
        $stageName = mb_strtolower((string) ($chat->funnelStage?->name ?? ''));

        if (preg_match('/выиг|won|успех|закрыт/u', $stageName)) {
            return 85;
        }
        if (preg_match('/предлож|коммерч|оффер/u', $stageName)) {
            return 55;
        }
        if (preg_match('/запис|встреч|замер/u', $stageName)) {
            return 65;
        }
        if (preg_match('/проиг|lost|отказ/u', $stageName)) {
            return 10;
        }

        return 35;
    }

    private function industryHistoricalBonus(int $companyId): int
    {
        if (! Schema::hasTable('deal_outcomes')) {
            return 0;
        }

        $total = DealOutcome::query()->where('company_id', $companyId)->count();
        if ($total < 5) {
            return 0;
        }

        $won = DealOutcome::query()->where('company_id', $companyId)->where('won', true)->count();
        $rate = $won / max(1, $total);

        return (int) round(($rate - 0.3) * 20);
    }

    /**
     * @param  array<string, mixed>  $state
     * @return list<string>
     */
    private function riskFactors(array $state): array
    {
        $factors = [];

        if (($state['budget_known'] ?? false) !== true) {
            $factors[] = 'budget_unknown';
        }
        if (($state['requirements_known'] ?? false) !== true) {
            $factors[] = 'requirements_unknown';
        }
        if (($state['decision_maker_known'] ?? false) !== true) {
            $factors[] = 'decision_maker_unknown';
        }
        if (($state['deferral_detected'] ?? false) === true) {
            $factors[] = 'deferral_active';
        }

        $objections = is_array($state['objections_open'] ?? null) ? $state['objections_open'] : [];
        foreach (array_slice($objections, 0, 3) as $objection) {
            $factors[] = 'objection_open:'.mb_substr((string) $objection, 0, 40);
        }

        return $factors;
    }
}
