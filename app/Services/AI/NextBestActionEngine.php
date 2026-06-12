<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\Chat;
use App\Models\SalesPlaybook;
use App\Services\Contact\StakeholderDetectionService;
use App\Support\AiFeatureFlags;

final class NextBestActionEngine
{
    public function __construct(
        private readonly ChatSalesStateService $salesStateService,
        private readonly PlaybookSelector $playbookSelector,
        private readonly StakeholderDetectionService $stakeholders,
    ) {}

    /**
     * @return array{
     *     current_stage: string,
     *     goal: string,
     *     confidence: float,
     *     next_best_action: string,
     *     reasoning: string
     * }
     */
    public function compute(Chat $chat): array
    {
        $state = $this->salesStateService->freshState($chat);
        $action = (string) ($state['next_action'] ?? ChatSalesStateService::NA_QUALIFY);
        $playbook = $this->playbookSelector->resolveForChat($chat);
        $action = $this->guardDecisionMakerForB2b($chat, $state, $action, $playbook);

        $confidence = $this->confidenceFromState($state);
        $goal = $this->goalForAction($action);
        $stage = $this->currentStageLabel($chat, $state);
        $reasoning = $this->buildReasoning($state, $action, $playbook?->name);

        return [
            'current_stage' => $stage,
            'goal' => $goal,
            'confidence' => round($confidence, 2),
            'next_best_action' => $action,
            'reasoning' => $reasoning,
        ];
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function confidenceFromState(array $state): float
    {
        $signals = 0;
        $total = 6;

        if (($state['qualified'] ?? false) === true) {
            $signals++;
        }
        if (($state['budget_known'] ?? false) === true) {
            $signals++;
        }
        if (($state['requirements_known'] ?? false) === true) {
            $signals++;
        }
        if (($state['timeline_known'] ?? false) === true) {
            $signals++;
        }
        if (($state['decision_maker_known'] ?? false) === true) {
            $signals++;
        }
        if (empty($state['objections_open'] ?? [])) {
            $signals++;
        }

        $base = 0.45 + ($signals / $total) * 0.45;

        if (($state['deferral_detected'] ?? false) === true) {
            $base -= 0.15;
        }

        return min(0.95, max(0.25, $base));
    }

    private function goalForAction(string $action): string
    {
        return match ($action) {
            ChatSalesStateService::NA_ASK_BUDGET => 'confirm_budget',
            ChatSalesStateService::NA_ASK_REQUIREMENTS => 'clarify_requirements',
            ChatSalesStateService::NA_PRESENT_OFFER => 'present_offer',
            ChatSalesStateService::NA_HANDLE_OBJECTION => 'resolve_objection',
            ChatSalesStateService::NA_BOOK_APPOINTMENT => 'book_meeting',
            ChatSalesStateService::NA_CONFIRM_DEAL => 'confirm_deal',
            ChatSalesStateService::NA_FOLLOW_UP => 're_engage_lead',
            default => 'complete_qualification',
        };
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function currentStageLabel(Chat $chat, array $state): string
    {
        if (($state['qualified'] ?? false) !== true) {
            return 'qualification';
        }

        if (($state['next_action'] ?? '') === ChatSalesStateService::NA_PRESENT_OFFER) {
            return 'proposal';
        }

        if (($state['next_action'] ?? '') === ChatSalesStateService::NA_BOOK_APPOINTMENT) {
            return 'booking';
        }

        if ($chat->funnelStage !== null) {
            return mb_strtolower((string) $chat->funnelStage->name);
        }

        return 'nurture';
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function buildReasoning(array $state, string $action, ?string $playbookName): string
    {
        $parts = [];

        if ($playbookName !== null) {
            $parts[] = "Playbook: {$playbookName}.";
        }

        $grade = $state['grade'] ?? null;
        if ($grade !== null) {
            $parts[] = "Lead grade {$grade}.";
        }

        $missing = is_array($state['missing_fields'] ?? null) ? implode(', ', $state['missing_fields']) : '';
        if ($missing !== '') {
            $parts[] = "Missing: {$missing}.";
        }

        $objections = is_array($state['objections_open'] ?? null) ? count($state['objections_open']) : 0;
        if ($objections > 0) {
            $parts[] = "Open objections: {$objections}.";
        }

        $parts[] = "Recommended: {$action}.";

        return implode(' ', $parts);
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function guardDecisionMakerForB2b(
        Chat $chat,
        array $state,
        string $action,
        ?SalesPlaybook $playbook,
    ): string {
        if ($action !== ChatSalesStateService::NA_PRESENT_OFFER) {
            return $action;
        }

        if (($state['decision_maker_known'] ?? false) === true) {
            return $action;
        }

        if (! AiFeatureFlags::enabled(AiFeatureFlags::STAKEHOLDERS, (int) $chat->company_id)) {
            return $action;
        }

        $slug = mb_strtolower((string) ($playbook?->slug ?? ''));
        $requiresDm = str_contains($slug, 'b2b')
            || in_array('decision_maker', $this->playbookSelector->qualificationFieldOrder($playbook), true);

        if (! $requiresDm) {
            return $action;
        }

        if ($chat->contact_id !== null && $this->stakeholders->hasDecisionMaker((int) $chat->contact_id)) {
            return $action;
        }

        return ChatSalesStateService::NA_QUALIFY;
    }
}
