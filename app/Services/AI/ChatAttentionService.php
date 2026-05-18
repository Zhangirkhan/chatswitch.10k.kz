<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\AiOrchestratorRun;
use App\Models\Chat;
use App\Support\TenantCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

final class ChatAttentionService
{
    public const FILTER_ATTENTION = 'attention';

    private const WAITING_MINUTES = 10;

    public function waitingThreshold(): Carbon
    {
        return now()->subMinutes(self::WAITING_MINUTES);
    }

    /**
     * @param  Builder<Chat>  $query
     * @return Builder<Chat>
     */
    public function applyAttentionScope(Builder $query): Builder
    {
        $waitingThreshold = $this->waitingThreshold();

        return $query
            ->where('company_id', TenantCompany::id())
            ->where('is_archived', false)
            ->where('is_group', false)
            ->where(function (Builder $inner) use ($waitingThreshold): void {
                $inner
                    ->whereIn('ai_orchestrator_status', [
                        AiOrchestratorRun::STATUS_NEEDS_MANAGER,
                        AiOrchestratorRun::STATUS_FAILED,
                    ])
                    ->orWhere(function (Builder $waiting) use ($waitingThreshold): void {
                        $waiting
                            ->where('last_message_direction', 'inbound')
                            ->whereNotNull('last_message_at')
                            ->where('last_message_at', '<=', $waitingThreshold);
                    })
                    ->orWhere('unread_count', '>', 0);
            })
            ->orderByRaw("CASE
                WHEN ai_orchestrator_status = 'needs_manager' THEN 0
                WHEN ai_orchestrator_status = 'failed' THEN 1
                WHEN last_message_direction = 'inbound' THEN 2
                ELSE 3
            END")
            ->orderByDesc('last_message_at');
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
                ->with(['contact:id,name,push_name,phone_number', 'funnel:id,name', 'funnelStage:id,name']),
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
        if ($chat->ai_orchestrator_status === AiOrchestratorRun::STATUS_NEEDS_MANAGER) {
            return $chat->ai_orchestrator_last_summary ?: 'AI просит менеджера проверить диалог.';
        }

        if ($chat->ai_orchestrator_status === AiOrchestratorRun::STATUS_FAILED) {
            return $chat->ai_orchestrator_last_summary ?: 'AI-оркестратор завершился ошибкой.';
        }

        if ($chat->last_message_direction === 'inbound') {
            return 'Клиент ждёт ответа.';
        }

        if ((int) $chat->unread_count > 0) {
            return 'Есть непрочитанные сообщения.';
        }

        return 'Чат требует внимания.';
    }

    /**
     * @return 'critical'|'danger'|'warning'|'normal'
     */
    private function severity(Chat $chat): string
    {
        if ($chat->ai_orchestrator_status === AiOrchestratorRun::STATUS_NEEDS_MANAGER) {
            return 'critical';
        }

        if ($chat->ai_orchestrator_status === AiOrchestratorRun::STATUS_FAILED) {
            return 'danger';
        }

        $waitMinutes = $chat->last_message_at !== null
            ? (int) $chat->last_message_at->diffInMinutes(now())
            : 0;

        return $waitMinutes >= 30 ? 'warning' : 'normal';
    }
}
