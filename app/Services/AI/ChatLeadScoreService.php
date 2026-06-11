<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\Chat;
use App\Models\Message;
use Illuminate\Support\Carbon;

/**
 * Deterministic lead scoring — no LLM calls.
 *
 * Computes score (0–100) and grade (A/B/C) from BANT facts, engagement,
 * objections, and deferral state.
 */
final class ChatLeadScoreService
{
    public const GRADE_A = 'A';

    public const GRADE_B = 'B';

    public const GRADE_C = 'C';

    /**
     * @param  array<string, mixed>  $partialState  fields from ChatSalesStateService::compute
     * @param  array<string, string>  $facts
     * @return array{score: int, grade: string, score_factors: array<string, int>}
     */
    public function score(Chat $chat, array $partialState, array $facts = []): array
    {
        $factors = [];

        $budgetPts = ($partialState['budget_known'] ?? false) === true ? 20 : 0;
        $factors['budget'] = $budgetPts;

        $reqPts = ($partialState['requirements_known'] ?? false) === true ? 15 : 0;
        $factors['requirements'] = $reqPts;

        $timelinePts = 0;
        if (($partialState['timeline_known'] ?? false) === true) {
            $timelinePts = 15;
            $timelineText = mb_strtolower(trim($facts['timeline'] ?? ''));
            if ($timelineText !== '' && preg_match('/срочн|быстр|сегодня|завтра|urgent| asap/u', $timelineText) === 1) {
                $timelinePts += 5;
            }
        }
        $factors['timeline'] = $timelinePts;

        $dmPts = ($partialState['decision_maker_known'] ?? false) === true ? 15 : 0;
        $factors['decision_maker'] = $dmPts;

        $engagementPts = $this->engagementPoints($chat);
        $factors['engagement'] = $engagementPts;

        $objectionsOpen = $partialState['objections_open'] ?? [];
        $objectionCount = is_array($objectionsOpen) ? count($objectionsOpen) : 0;
        $objectionsPenalty = min(15, $objectionCount * 5);
        $factors['objections_penalty'] = -$objectionsPenalty;

        $deferralPenalty = 0;
        if (($partialState['deferral_detected'] ?? false) === true) {
            $deferralPenalty = 20;
            $factors['deferral_penalty'] = -$deferralPenalty;
        }

        $raw = $budgetPts + $reqPts + $timelinePts + $dmPts + $engagementPts - $objectionsPenalty - $deferralPenalty;
        $score = max(0, min(100, $raw));

        return [
            'score' => $score,
            'grade' => $this->gradeForScore($score),
            'score_factors' => $factors,
        ];
    }

    private function engagementPoints(Chat $chat): int
    {
        if ($chat->id === null) {
            return 0;
        }

        $since = now()->subDays(7);
        $inboundCount = Message::query()
            ->where('chat_id', $chat->id)
            ->where('direction', 'inbound')
            ->where('created_at', '>=', $since)
            ->count();

        $pts = 0;
        if ($inboundCount >= 1) {
            $pts += 5;
        }
        if ($inboundCount >= 3) {
            $pts += 5;
        }
        if ($inboundCount >= 6) {
            $pts += 5;
        }

        $lastAt = $chat->last_message_at;
        if ($lastAt instanceof Carbon) {
            $hoursSince = $lastAt->diffInHours(now());
            if ($hoursSince <= 24) {
                $pts += 5;
            } elseif ($hoursSince <= 72) {
                $pts += 3;
            }
        }

        if ($chat->last_message_direction === 'inbound') {
            $pts += 5;
        }

        return min(25, $pts);
    }

    private function gradeForScore(int $score): string
    {
        if ($score >= 70) {
            return self::GRADE_A;
        }
        if ($score >= 40) {
            return self::GRADE_B;
        }

        return self::GRADE_C;
    }
}
