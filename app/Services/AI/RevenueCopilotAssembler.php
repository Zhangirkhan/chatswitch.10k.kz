<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\Chat;
use App\Models\ConversationAudit;
use App\Models\Contact;
use Illuminate\Support\Facades\Schema;

final class RevenueCopilotAssembler
{
    public function __construct(
        private readonly WinProbabilityService $winProbability,
        private readonly NextBestActionEngine $nbaEngine,
        private readonly ObjectionIntelligenceService $objectionIntel,
        private readonly ChatSalesStateService $salesStateService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function buildForChat(Chat $chat): array
    {
        $state = $this->salesStateService->freshState($chat);
        $winProb = $this->winProbability->latestForChat($chat) ?? $this->winProbability->compute($chat);
        $nba = $this->nbaEngine->compute($chat);

        $latestAudit = null;
        if (Schema::hasTable('conversation_audits')) {
            $audit = ConversationAudit::query()
                ->where('chat_id', $chat->id)
                ->orderByDesc('created_at')
                ->first();

            if ($audit !== null) {
                $latestAudit = [
                    'sales_score' => $audit->sales_score,
                    'conversation_quality' => $audit->conversation_quality,
                    'risk_level' => $audit->risk_level,
                    'missed_questions' => $audit->missed_questions ?? [],
                ];
            }
        }

        return [
            'win_probability' => $winProb['win_probability'],
            'risk_factors' => $winProb['risk_factors'],
            'recommended_action' => $winProb['recommended_action'],
            'next_best_action' => $nba,
            'missing_fields' => is_array($state['missing_fields'] ?? null) ? $state['missing_fields'] : [],
            'objections_open' => is_array($state['objections_open'] ?? null) ? $state['objections_open'] : [],
            'lead_grade' => $state['grade'] ?? null,
            'lead_score' => $state['score'] ?? null,
            'latest_audit' => $latestAudit,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function buildForContact(Contact $contact, ?int $preferredChatId = null): array
    {
        $chatQuery = Chat::query()
            ->where('contact_id', $contact->id)
            ->where('is_group', false)
            ->orderByDesc('last_message_at');

        $chat = $preferredChatId !== null
            ? (clone $chatQuery)->whereKey($preferredChatId)->first() ?? $chatQuery->first()
            : $chatQuery->first();

        if ($chat === null) {
            return [
                'win_probability' => null,
                'objection_summary' => $this->objectionIntel->buildForCompany((int) $contact->company_id),
            ];
        }

        return array_merge(
            $this->buildForChat($chat),
            ['objection_summary' => $this->objectionIntel->buildForCompany((int) $contact->company_id)],
        );
    }
}
