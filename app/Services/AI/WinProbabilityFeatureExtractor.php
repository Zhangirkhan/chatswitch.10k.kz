<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\Chat;
use App\Models\DealOutcome;
use Illuminate\Support\Facades\Schema;

final class WinProbabilityFeatureExtractor
{
    /** @var list<string> */
    public const FEATURE_KEYS = [
        'budget_known',
        'requirements_known',
        'timeline_known',
        'decision_maker_known',
        'lead_score_norm',
        'objection_count_norm',
        'deferral_detected',
        'stage_score',
        'industry_win_rate',
    ];

    /**
     * @param  array<string, mixed>  $state
     * @return list<float>
     */
    public function vectorFromState(array $state, Chat $chat, int $companyId): array
    {
        $bantFields = ['budget_known', 'requirements_known', 'timeline_known', 'decision_maker_known'];
        $vector = [];
        foreach ($bantFields as $field) {
            $vector[] = ($state[$field] ?? false) === true ? 1.0 : 0.0;
        }

        $score = isset($state['score']) ? min(100, max(0, (int) $state['score'])) : 0;
        $vector[] = $score / 100;

        $objections = is_array($state['objections_open'] ?? null) ? count($state['objections_open']) : 0;
        $vector[] = min(1.0, $objections / 5);

        $vector[] = ($state['deferral_detected'] ?? false) === true ? 1.0 : 0.0;
        $vector[] = $this->stageScore($chat);
        $vector[] = $this->industryWinRate($companyId);

        return $vector;
    }

    /**
     * @return list<float>
     */
    public function vectorForChat(Chat $chat, ChatSalesStateService $salesStateService): array
    {
        $state = $salesStateService->freshState($chat);

        return $this->vectorFromState($state, $chat, (int) $chat->company_id);
    }

    private function stageScore(Chat $chat): float
    {
        $stageName = mb_strtolower((string) ($chat->funnelStage?->name ?? ''));

        if (preg_match('/выиг|won|успех|закрыт/u', $stageName)) {
            return 0.95;
        }
        if (preg_match('/предлож|коммерч|оффер/u', $stageName)) {
            return 0.65;
        }
        if (preg_match('/запис|встреч|замер/u', $stageName)) {
            return 0.75;
        }
        if (preg_match('/проиг|lost|отказ/u', $stageName)) {
            return 0.05;
        }

        return 0.35;
    }

    private function industryWinRate(int $companyId): float
    {
        if (! Schema::hasTable('deal_outcomes')) {
            return 0.5;
        }

        $total = DealOutcome::query()->where('company_id', $companyId)->count();
        if ($total < 5) {
            return 0.5;
        }

        $won = DealOutcome::query()->where('company_id', $companyId)->where('won', true)->count();

        return $won / max(1, $total);
    }
}
