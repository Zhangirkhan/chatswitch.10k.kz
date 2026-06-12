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
        private readonly WinProbabilityFeatureExtractor $featureExtractor,
        private readonly WinProbabilityModelRegistry $modelRegistry,
    ) {}

    /**
     * @return array{
     *     win_probability: int,
     *     risk_factors: list<string>,
     *     recommended_action: string,
     *     model: array{type: string, version: int|null}
     * }
     */
    public function compute(Chat $chat): array
    {
        $state = $this->salesStateService->freshState($chat);
        $companyId = (int) $chat->company_id;
        $features = $this->featureExtractor->vectorFromState($state, $chat, $companyId);

        $activeModel = AiFeatureFlags::enabled(AiFeatureFlags::ML_WIN_PROB, $companyId)
            ? $this->modelRegistry->activeForCompany($companyId)
            : null;

        if ($activeModel !== null) {
            $probability = (int) round(min(95, max(5, $this->modelRegistry->predictProbability($activeModel, $features) * 100)));
            $modelMeta = ['type' => 'ml', 'version' => (int) $activeModel->version];
        } else {
            $heuristic = $this->predictHeuristic($state, $chat, $companyId);
            $probability = $heuristic['win_probability'];
            $modelMeta = ['type' => 'heuristic', 'version' => null];
        }

        $riskFactors = $this->riskFactors($state);
        $recommendedAction = (string) ($state['next_action'] ?? ChatSalesStateService::NA_QUALIFY);

        $result = [
            'win_probability' => $probability,
            'risk_factors' => $riskFactors,
            'recommended_action' => $recommendedAction,
            'model' => $modelMeta,
        ];

        if (AiFeatureFlags::enabled(AiFeatureFlags::SALES_STATE, $companyId)
            && Schema::hasTable('win_probability_scores')
        ) {
            WinProbabilityScore::query()->create([
                'company_id' => $companyId,
                'chat_id' => (int) $chat->id,
                'probability' => $probability,
                'risk_factors' => $riskFactors,
                'recommended_action' => $recommendedAction,
                'inputs_snapshot' => [
                    'sales_state' => $state,
                    'funnel_stage_id' => $chat->funnel_stage_id,
                    'features' => $features,
                    'model' => $modelMeta,
                ],
                'computed_at' => now(),
            ]);
        }

        return $result;
    }

    /**
     * @return array{win_probability: int, risk_factors: list<string>, recommended_action: string}
     */
    public function computeHeuristicOnly(Chat $chat): array
    {
        $state = $this->salesStateService->freshState($chat);
        $heuristic = $this->predictHeuristic($state, $chat, (int) $chat->company_id);

        return [
            'win_probability' => $heuristic['win_probability'],
            'risk_factors' => $this->riskFactors($state),
            'recommended_action' => (string) ($state['next_action'] ?? ChatSalesStateService::NA_QUALIFY),
        ];
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

        $snapshot = is_array($row->inputs_snapshot) ? $row->inputs_snapshot : [];
        $model = is_array($snapshot['model'] ?? null) ? $snapshot['model'] : ['type' => 'heuristic', 'version' => null];

        return [
            'win_probability' => (int) $row->probability,
            'risk_factors' => is_array($row->risk_factors) ? $row->risk_factors : [],
            'recommended_action' => (string) ($row->recommended_action ?? ChatSalesStateService::NA_QUALIFY),
            'model' => [
                'type' => (string) ($model['type'] ?? 'heuristic'),
                'version' => isset($model['version']) ? (int) $model['version'] : null,
            ],
        ];
    }

    public function activeModelLabel(int $companyId): array
    {
        if (! AiFeatureFlags::enabled(AiFeatureFlags::ML_WIN_PROB, $companyId)) {
            return ['type' => 'heuristic', 'version' => null];
        }

        $model = $this->modelRegistry->activeForCompany($companyId);

        return $model !== null
            ? ['type' => 'ml', 'version' => (int) $model->version]
            : ['type' => 'heuristic', 'version' => null];
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array{win_probability: int}
     */
    private function predictHeuristic(array $state, Chat $chat, int $companyId): array
    {
        $stageBase = $this->stageBaseProbability($chat);
        $scoreNorm = isset($state['score']) ? min(100, max(0, (int) $state['score'])) / 100 : 0.3;

        $bantFields = ['budget_known', 'requirements_known', 'timeline_known', 'decision_maker_known'];
        $bantCount = count(array_filter($bantFields, static fn (string $f): bool => ($state[$f] ?? false) === true));
        $bantBonus = $bantCount * 5;

        $objections = is_array($state['objections_open'] ?? null) ? count($state['objections_open']) : 0;
        $objectionPenalty = min(20, $objections * 7);

        $deferralPenalty = ($state['deferral_detected'] ?? false) === true ? 12 : 0;
        $industryBonus = $this->industryHistoricalBonus($companyId);

        $probability = (int) round(min(95, max(5,
            $stageBase
            + ($scoreNorm * 25)
            + $bantBonus
            - $objectionPenalty
            - $deferralPenalty
            + $industryBonus
        )));

        return ['win_probability' => $probability];
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
