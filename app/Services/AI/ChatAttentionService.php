<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\AiFollowUpProposal;
use App\Models\AiOrchestratorRun;
use App\Models\Chat;
use App\Models\ConversationAudit;
use App\Support\TenantCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Carbon;

final class ChatAttentionService
{
    public const FILTER_ATTENTION = 'attention';

    public function attentionConfidenceMax(): float
    {
        return (float) config('funnel.orchestrator.attention_confidence_max', 0.85);
    }

    public function attentionRunSince(): Carbon
    {
        $days = max(1, (int) config('funnel.orchestrator.attention_run_days', 14));

        return now()->subDays($days);
    }

    /**
     * @param  Builder<Chat>  $query
     * @return Builder<Chat>
     */
    public function applyAttentionScope(Builder $query): Builder
    {
        $confidenceMax = $this->attentionConfidenceMax();
        $runSince = $this->attentionRunSince();

        return $query
            ->where('company_id', TenantCompany::id())
            ->where('is_archived', false)
            ->where('is_group', false)
            ->where(function (Builder $inner) use ($confidenceMax, $runSince): void {
                $inner
                    ->whereIn('ai_orchestrator_status', [
                        AiOrchestratorRun::STATUS_NEEDS_MANAGER,
                        AiOrchestratorRun::STATUS_FAILED,
                    ])
                    ->orWhereHas('aiFollowUpProposals', function (Builder $proposalQuery): void {
                        $proposalQuery->where('status', AiFollowUpProposal::STATUS_NEEDS_MANAGER);
                    })
                    ->orWhere('unread_count', '>', 0)
                    ->orWhere(function (Builder $uncertain) use ($confidenceMax, $runSince): void {
                        $this->applyLowConfidenceLastRunScope($uncertain, $confidenceMax, $runSince);
                    })
                    ->orWhere(function (Builder $highRisk): void {
                        $this->applyHighRiskAuditScope($highRisk);
                    });
            })
            ->orderByRaw("CASE
                WHEN ai_orchestrator_status = 'needs_manager' THEN 0
                WHEN EXISTS (
                    SELECT 1 FROM ai_follow_up_proposals fp
                    WHERE fp.chat_id = chats.id AND fp.status = ?
                ) THEN 0
                WHEN ai_orchestrator_status = 'failed' THEN 1
                WHEN EXISTS (
                    SELECT 1 FROM ai_orchestrator_runs r
                    WHERE r.id = chats.ai_orchestrator_last_run_id
                      AND r.confidence IS NOT NULL
                      AND r.confidence < ?
                      AND r.status = ?
                      AND r.completed_at >= ?
                ) THEN 2
                WHEN EXISTS (
                    SELECT 1 FROM conversation_audits ca
                    WHERE ca.chat_id = chats.id
                      AND ca.risk_level = 'high'
                      AND ca.created_at >= ?
                ) THEN 2
                ELSE 3
            END", [
                AiFollowUpProposal::STATUS_NEEDS_MANAGER,
                $confidenceMax,
                AiOrchestratorRun::STATUS_COMPLETED,
                $runSince,
                $runSince,
            ])
            ->orderByDesc('last_message_at');
    }

    /**
     * @param  Builder<Chat>  $query
     */
    private function applyHighRiskAuditScope(Builder $query): void
    {
        if (! Schema::hasTable('conversation_audits')) {
            return;
        }

        $query->whereExists(function ($sub): void {
            $sub->from('conversation_audits as ca')
                ->selectRaw('1')
                ->whereColumn('ca.chat_id', 'chats.id')
                ->where('ca.risk_level', 'high')
                ->where('ca.created_at', '>=', $this->attentionRunSince());
        });
    }

    /**
     * @param  Builder<Chat>  $query
     */
    private function applyLowConfidenceLastRunScope(Builder $query, float $confidenceMax, Carbon $runSince): void
    {
        $query->whereNotNull('ai_orchestrator_last_run_id')
            ->whereExists(function ($sub) use ($confidenceMax, $runSince): void {
                $sub->from('ai_orchestrator_runs as r')
                    ->selectRaw('1')
                    ->whereColumn('r.id', 'chats.ai_orchestrator_last_run_id')
                    ->whereNotNull('r.confidence')
                    ->where('r.confidence', '<', $confidenceMax)
                    ->where('r.status', AiOrchestratorRun::STATUS_COMPLETED)
                    ->where('r.completed_at', '>=', $runSince);
            });
    }

    public function countEligible(): int
    {
        return $this->applyAttentionScope(Chat::query())->count();
    }

    /**
     * @return array{reason: string, severity: 'critical'|'danger'|'warning'|'normal'}
     */
    public function describe(Chat $chat): array
    {
        return [
            'reason' => $this->reason($chat),
            'severity' => $this->severity($chat),
        ];
    }

    /**
     * @return list<array{id: int, chat_name: string, reason: string, severity: string, wait_minutes: int|null, ai_status: string|null, last_message_at: string|null, unread_count: int, funnel: string|null, stage: string|null}>
     */
    public function queue(int $limit = 30): array
    {
        return $this->applyAttentionScope(
            Chat::query()
                ->with([
                    'contact:id,name,push_name,phone_number',
                    'funnel:id,name',
                    'funnelStage:id,name',
                    'lastOrchestratorRun:id,confidence,status,reason,completed_at',
                ]),
        )
            ->limit($limit)
            ->get([
                'id',
                'contact_id',
                'chat_name',
                'last_message_at',
                'last_message_direction',
                'unread_count',
                'ai_orchestrator_status',
                'ai_orchestrator_last_run_id',
                'ai_orchestrator_last_summary',
                'funnel_id',
                'funnel_stage_id',
            ])
            ->map(fn (Chat $chat): array => [
                'id' => $chat->id,
                'chat_name' => $this->displayName($chat),
                'reason' => $this->reason($chat),
                'severity' => $this->severity($chat),
                'wait_minutes' => $chat->last_message_at !== null
                    ? max(0, (int) $chat->last_message_at->diffInMinutes(now()))
                    : null,
                'ai_status' => $chat->ai_orchestrator_status,
                'last_message_at' => $chat->last_message_at?->toIso8601String(),
                'unread_count' => (int) $chat->unread_count,
                'funnel' => $chat->funnel?->name,
                'stage' => $chat->funnelStage?->name,
            ])
            ->values()
            ->all();
    }

    private function displayName(Chat $chat): string
    {
        return $chat->chat_name
            ?: $chat->contact?->name
            ?: $chat->contact?->push_name
            ?: $chat->contact?->phone_number
            ?: 'Чат #'.$chat->id;
    }

    private function reason(Chat $chat): string
    {
        if ($this->hasPendingFollowUpProposal($chat)) {
            return 'AI подготовил варианты дожима — выберите и отправьте клиенту.';
        }

        if ($chat->ai_orchestrator_status === AiOrchestratorRun::STATUS_NEEDS_MANAGER) {
            return $chat->ai_orchestrator_last_summary ?: 'AI просит менеджера проверить диалог.';
        }

        if ($chat->ai_orchestrator_status === AiOrchestratorRun::STATUS_FAILED) {
            return $chat->ai_orchestrator_last_summary ?: 'AI-оркестратор завершился ошибкой.';
        }

        $uncertain = $this->uncertainRunReason($chat);
        if ($uncertain !== null) {
            return $uncertain;
        }

        if ($this->hasHighRiskAudit($chat)) {
            return 'Аудитор AI оценил диалог как высокий риск потери сделки.';
        }

        if ((int) $chat->unread_count > 0) {
            return 'Есть непрочитанные сообщения.';
        }

        return '';
    }

    /**
     * @return 'critical'|'danger'|'warning'|'normal'
     */
    private function severity(Chat $chat): string
    {
        if ($this->hasPendingFollowUpProposal($chat)) {
            return 'critical';
        }

        if ($chat->ai_orchestrator_status === AiOrchestratorRun::STATUS_NEEDS_MANAGER) {
            return 'critical';
        }

        if ($chat->ai_orchestrator_status === AiOrchestratorRun::STATUS_FAILED) {
            return 'danger';
        }

        if ($this->hasUncertainLastRun($chat)) {
            return 'warning';
        }

        if ($this->hasHighRiskAudit($chat)) {
            return 'warning';
        }

        if ((int) $chat->unread_count > 0) {
            return 'warning';
        }

        return 'normal';
    }

    private function uncertainRunReason(Chat $chat): ?string
    {
        $run = $chat->relationLoaded('lastOrchestratorRun')
            ? $chat->lastOrchestratorRun
            : null;

        if (! $this->isUncertainRun($run)) {
            return null;
        }

        $pct = (int) round(((float) $run->confidence) * 100);
        $detail = trim((string) ($run->reason ?: $chat->ai_orchestrator_last_summary));

        return $detail !== ''
            ? "AI не уверен ({$pct}%): {$detail}"
            : "AI не уверен в следующем шаге ({$pct}%).";
    }

    private function hasUncertainLastRun(Chat $chat): bool
    {
        $run = $chat->relationLoaded('lastOrchestratorRun')
            ? $chat->lastOrchestratorRun
            : null;

        return $this->isUncertainRun($run);
    }

    private function isUncertainRun(?AiOrchestratorRun $run): bool
    {
        if ($run === null || $run->confidence === null) {
            return false;
        }

        if ((string) $run->status !== AiOrchestratorRun::STATUS_COMPLETED) {
            return false;
        }

        if ($run->completed_at === null || $run->completed_at->lt($this->attentionRunSince())) {
            return false;
        }

        return (float) $run->confidence < $this->attentionConfidenceMax();
    }

    private function hasHighRiskAudit(Chat $chat): bool
    {
        if (! Schema::hasTable('conversation_audits')) {
            return false;
        }

        return ConversationAudit::query()
            ->where('chat_id', $chat->id)
            ->where('risk_level', 'high')
            ->where('created_at', '>=', $this->attentionRunSince())
            ->exists();
    }

    private function hasPendingFollowUpProposal(Chat $chat): bool
    {
        if ($chat->relationLoaded('aiFollowUpProposals')) {
            return $chat->aiFollowUpProposals
                ->contains(fn (AiFollowUpProposal $proposal): bool => $proposal->status === AiFollowUpProposal::STATUS_NEEDS_MANAGER);
        }

        return AiFollowUpProposal::query()
            ->where('chat_id', $chat->id)
            ->where('status', AiFollowUpProposal::STATUS_NEEDS_MANAGER)
            ->exists();
    }
}
