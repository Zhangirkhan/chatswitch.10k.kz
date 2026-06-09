<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Events\ChatsListNotify;
use App\Models\Chat;
use App\Models\Department;
use App\Models\DepartmentPost;
use App\Models\FunnelAiScenario;
use App\Models\Message;
use App\Models\User;
use App\Services\AI\Orchestrator\ClientSituation;
use App\Services\AI\Orchestrator\ClientSituationDetector;
use App\Services\AI\Orchestrator\DeescalationReplyBuilder;
use App\Services\ChatService;
use App\Services\TeamChatService;
use App\Services\TeamDepartmentChatSyncService;
use App\Support\ChatBroadcastAudience;
use App\Support\ChatUrl;
use App\Support\MessageInboundText;
use App\Support\SafeBroadcast;

final class ChatConflictService
{
    public const STATE_NONE = 'none';

    public const STATE_DEESCALATING = 'deescalating';

    public const STATE_ESCALATED = 'escalated';

    public function __construct(
        private readonly ClientSituationDetector $detector,
        private readonly DeescalationReplyBuilder $replyBuilder,
        private readonly ChatService $chatService,
        private readonly TeamChatService $teamChatService,
        private readonly TeamDepartmentChatSyncService $teamDepartmentChatSync,
    ) {}

    public function enabled(): bool
    {
        return (bool) config('accel.conflict_handling.enabled', true);
    }

    public function isAiPausedForConflict(Chat $chat): bool
    {
        return $chat->conflict_state === self::STATE_ESCALATED
            || $chat->ai_paused_at !== null;
    }

    /**
     * @return array{reply: string, escalate: bool, situation: ClientSituation}|null
     */
    public function resolveForInbound(Chat $chat, Message $trigger, User $actor, bool $deferSideEffects = false): ?array
    {
        if (! $this->enabled() || $chat->is_group) {
            return null;
        }

        if ($this->isAiPausedForConflict($chat)) {
            return null;
        }

        $body = trim(MessageInboundText::forMessage($trigger));
        $situation = $this->detector->detect($body, $chat, $trigger);

        if ($situation->tier === 0 && $situation->situation !== ClientSituation::SITUATION_NONE) {
            return [
                'reply' => $this->replyBuilder->build($situation, $body, (int) $chat->company_id, 0, false),
                'escalate' => false,
                'situation' => $situation,
            ];
        }

        if (! $situation->isConflict()) {
            if ($chat->conflict_state === self::STATE_DEESCALATING) {
                $chat->forceFill([
                    'conflict_state' => self::STATE_NONE,
                    'conflict_situation' => null,
                    'conflict_deescalation_count' => 0,
                ])->save();
            }

            return null;
        }

        if ($chat->conflict_state === self::STATE_NONE) {
            $chat->forceFill([
                'conflict_state' => self::STATE_DEESCALATING,
                'conflict_situation' => $situation->situation,
                'conflict_deescalation_count' => 0,
            ])->save();
        }

        $maxAttempts = $this->maxDeescalationAttempts($situation->tier);
        $currentCount = (int) $chat->conflict_deescalation_count;

        if ($currentCount >= $maxAttempts) {
            $reply = $this->replyBuilder->build($situation, $body, (int) $chat->company_id, $currentCount, true);
            $this->escalate($chat, $trigger, $situation, $actor, $reply, $deferSideEffects);

            return [
                'reply' => $reply,
                'escalate' => true,
                'situation' => $situation,
            ];
        }

        $reply = $this->replyBuilder->build($situation, $body, (int) $chat->company_id, $currentCount, false);
        $chat->forceFill([
            'conflict_deescalation_count' => $currentCount + 1,
            'conflict_situation' => $situation->situation,
        ])->save();

        $shouldEscalateAfter = ($currentCount + 1) >= $maxAttempts;

        if ($shouldEscalateAfter) {
            $this->escalate($chat, $trigger, $situation, $actor, $reply, $deferSideEffects);
        }

        return [
            'reply' => $reply,
            'escalate' => $shouldEscalateAfter,
            'situation' => $situation,
        ];
    }

    public function escalate(Chat $chat, Message $trigger, ClientSituation $situation, User $actor, ?string $lastReply = null, bool $deferSideEffects = false): void
    {
        $chat->forceFill([
            'conflict_state' => self::STATE_ESCALATED,
            'conflict_situation' => $situation->situation,
            'ai_paused_at' => now(),
        ])->save();

        if ($deferSideEffects) {
            return;
        }

        $clientQuote = mb_substr(trim(MessageInboundText::forMessage($trigger)), 0, 400);
        $note = sprintf(
            'Конфликтная ситуация (%s, tier %d). Клиент: «%s»',
            $situation->situation,
            $situation->tier,
            $clientQuote,
        );

        if ($lastReply !== null && $lastReply !== '') {
            $note .= "\n\nПоследний ответ AI: ".$lastReply;
        }

        $this->chatService->logSystemMessage($chat, 'AI: '.$note);

        $scenario = $chat->funnel?->aiScenario;
        if ($scenario instanceof FunnelAiScenario) {
            $this->notifyViaScenario($chat, $actor, $scenario, $note);
            $this->createTask($actor, $scenario, $situation, $note);
        }

        $this->broadcastConflictAlert($chat, $note);
    }

    public function afterOrchestratorEscalation(Chat $chat, string $note): void
    {
        $this->broadcastConflictAlert($chat, $note);
    }

    public function clearConflict(Chat $chat): void
    {
        $chat->forceFill([
            'conflict_state' => self::STATE_NONE,
            'conflict_situation' => null,
            'conflict_deescalation_count' => 0,
            'ai_paused_at' => null,
        ])->save();
    }

    /**
     * @return array{customerReply: string, requiresManagerAttention: bool, managerNote: string, task: array{title: string, body: string}}|null
     */
    public function orchestratorOverride(Chat $chat, Message $trigger, User $actor): ?array
    {
        $resolved = $this->resolveForInbound($chat, $trigger, $actor, deferSideEffects: true);
        if ($resolved === null) {
            return null;
        }

        $note = sprintf(
            'Конфликт (%s). %s',
            $resolved['situation']->situation,
            $resolved['escalate'] ? 'AI приостановлен, нужен менеджер.' : 'De-escalation ответ.',
        );

        return [
            'customerReply' => $resolved['reply'],
            'requiresManagerAttention' => $resolved['escalate'],
            'managerNote' => $resolved['escalate'] ? $note : '',
            'task' => $resolved['escalate'] ? [
                'title' => 'Конфликт с клиентом',
                'body' => $note,
            ] : [],
        ];
    }

    private function maxDeescalationAttempts(int $tier): int
    {
        return match ($tier) {
            3 => max(0, (int) config('accel.conflict_handling.tier3_max_attempts', 0)),
            2 => max(1, (int) config('accel.conflict_handling.tier2_max_attempts', 1)),
            1 => max(1, (int) config('accel.conflict_handling.deescalation_max_attempts', 2)),
            default => PHP_INT_MAX,
        };
    }

    private function notifyViaScenario(Chat $chat, User $actor, FunnelAiScenario $scenario, string $note): void
    {
        $department = $scenario->fallbackDepartment;
        if (! $department instanceof Department) {
            return;
        }

        $conversation = $this->teamDepartmentChatSync->ensureDepartmentConversation($department);
        $url = ChatUrl::show($chat);
        $this->teamChatService->sendMessage(
            $actor,
            $conversation,
            "⚠️ Конфликт с клиентом — нужен менеджер:\n{$note}\n\n{$url}",
        );
    }

    private function createTask(User $actor, FunnelAiScenario $scenario, ClientSituation $situation, string $note): void
    {
        $department = $scenario->fallbackDepartment;
        if (! $department instanceof Department) {
            return;
        }

        $post = DepartmentPost::query()->create([
            'department_id' => $department->id,
            'author_id' => $actor->id,
            'title' => 'Конфликт: '.$situation->situation,
            'body' => $note,
            'status' => DepartmentPost::STATUS_OPEN,
            'due_at' => null,
        ]);

        if ($scenario->fallback_manager_user_id !== null) {
            $post->assignees()->sync([$scenario->fallback_manager_user_id]);
        }
    }

    private function broadcastConflictAlert(Chat $chat, string $note): void
    {
        $recipients = ChatBroadcastAudience::userIdsWithAccessToChat($chat);
        if ($recipients === []) {
            return;
        }

        SafeBroadcast::dispatch(new ChatsListNotify(
            chatId: $chat->id,
            kind: 'conflict_escalation',
            title: 'Нужен менеджер: конфликт',
            body: mb_substr($note, 0, 180),
            iconUrl: null,
            isMuted: false,
            recipientUserIds: $recipients,
            extra: [
                'conflict_state' => self::STATE_ESCALATED,
                'conflict_situation' => $chat->conflict_situation,
            ],
        ));
    }
}
