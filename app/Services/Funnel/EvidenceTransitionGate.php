<?php

declare(strict_types=1);

namespace App\Services\Funnel;

use App\Models\Chat;
use App\Models\FunnelStage;
use App\Support\AiFeatureFlags;
use Illuminate\Support\Facades\Log;

/**
 * Evidence-based gate for AI-initiated funnel stage transitions.
 *
 * Prevents the AI from advancing a lead into qualification, negotiation,
 * or closing stages without recorded evidence in the chat's sales_state:
 *
 *  Stage type        | Required evidence
 *  ------------------|---------------------------------------------------
 *  qualification     | budget_known OR requirements_known
 *  offer / proposal  | qualified = true
 *  closing / deal    | agreements non-empty
 *
 * Only active when the ai.sales_state feature flag is enabled (which implies
 * the sales_state column has been populated for this chat).
 *
 * Returns a non-null rejection reason string when the gate blocks the
 * transition; returns null when the transition is allowed.
 */
final class EvidenceTransitionGate
{
    /**
     * Stage name keywords that indicate a qualification stage.
     *
     * @var list<string>
     */
    private const QUALIFICATION_KEYWORDS = [
        'квалификац', 'квалифик', 'выявлени', 'потребност', 'интерес',
        'qualification', 'discovery',
    ];

    /**
     * Stage name keywords that indicate an offer / negotiation stage.
     *
     * @var list<string>
     */
    private const OFFER_KEYWORDS = [
        'предложен', 'коммерч', 'переговор', 'презентац', 'оффер',
        'offer', 'proposal', 'negotiat',
    ];

    /**
     * Stage name keywords that indicate a closing / deal stage.
     *
     * @var list<string>
     */
    private const CLOSING_KEYWORDS = [
        'оплат', 'счёт', 'счет', 'договор', 'сделк', 'закрыт', 'продан',
        'payment', 'deal', 'close', 'won',
    ];

    /**
     * Check whether evidence requirements are satisfied for the target stage.
     *
     * Returns null when the transition is allowed, or a short rejection reason
     * string when it is blocked.
     */
    public function rejectReason(Chat $chat, int $targetStageId): ?string
    {
        if (! AiFeatureFlags::enabled(AiFeatureFlags::SALES_STATE, $chat->company_id)) {
            return null;
        }

        $salesState = $chat->sales_state;
        if (! is_array($salesState) || $salesState === []) {
            // No state yet — allow transition (extraction hasn't run).
            return null;
        }

        $stage = FunnelStage::query()->find($targetStageId);
        if ($stage === null) {
            return null;
        }

        $stageName = mb_strtolower((string) ($stage->name ?? ''));

        $stageType = $this->classifyStage($stageName);

        $budgetKnown       = ($salesState['budget_known']       ?? false) === true;
        $requirementsKnown = ($salesState['requirements_known'] ?? false) === true;
        $qualified         = ($salesState['qualified']          ?? false) === true;
        $agreements        = $salesState['agreements'] ?? null;

        return match ($stageType) {
            'qualification' => (! $budgetKnown && ! $requirementsKnown)
                ? 'no_qualification_evidence (budget_known=false, requirements_known=false)'
                : null,
            'offer' => ! $qualified
                ? 'not_yet_qualified (budget_known='.($budgetKnown ? 'true' : 'false').', requirements_known='.($requirementsKnown ? 'true' : 'false').')'
                : null,
            'closing' => ($agreements === null || $agreements === '')
                ? 'no_agreement_recorded'
                : null,
            default => null,
        };
    }

    /**
     * Classify a stage by name into: qualification | offer | closing | other.
     */
    private function classifyStage(string $lowerName): string
    {
        foreach (self::CLOSING_KEYWORDS as $kw) {
            if (str_contains($lowerName, $kw)) {
                return 'closing';
            }
        }
        foreach (self::OFFER_KEYWORDS as $kw) {
            if (str_contains($lowerName, $kw)) {
                return 'offer';
            }
        }
        foreach (self::QUALIFICATION_KEYWORDS as $kw) {
            if (str_contains($lowerName, $kw)) {
                return 'qualification';
            }
        }

        return 'other';
    }
}
