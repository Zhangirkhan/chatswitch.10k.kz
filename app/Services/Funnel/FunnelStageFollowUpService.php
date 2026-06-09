<?php

declare(strict_types=1);

namespace App\Services\Funnel;

use App\Models\Chat;
use App\Models\FunnelAiScenario;
use App\Models\FunnelStageAiRule;
use App\Models\ScheduledMessage;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class FunnelStageFollowUpService
{
    public const DEFAULT_MESSAGE = 'Добрый день, {chat_name}! Подскажите, готовы перейти к этапу «{next_stage_name}»? Если остались вопросы — напишите, поможем.';

    public function __construct(
        private readonly FunnelFollowUpAiTextService $aiText,
        private readonly FunnelStageSequenceService $stageSequence,
    ) {}

    public function isModuleEnabled(): bool
    {
        return SystemSetting::getValue('module_funnels', 'on') === 'on';
    }

    public function scheduleDue(int $limit = 80): int
    {
        if (! $this->isModuleEnabled()) {
            return 0;
        }

        $created = 0;
        $rules = FunnelStageAiRule::query()
            ->with(['stage:id,funnel_id,name,position', 'funnel.aiScenario', 'funnel.stages:id,funnel_id,name,position,is_active'])
            ->orderBy('id')
            ->get();

        foreach ($rules as $rule) {
            if ($created >= $limit) {
                break;
            }

            if (! $rule instanceof FunnelStageAiRule || $rule->stage === null) {
                continue;
            }

            if (! $this->usesAutoCronFollowUp($rule)) {
                continue;
            }

            if ($this->stageSequence->nextStageForRule($rule) === null) {
                continue;
            }

            $remaining = $limit - $created;
            $created += $this->scheduleForRule($rule, $remaining);
        }

        if ($created > 0) {
            Log::info('[funnel-follow-up] scheduled', ['count' => $created]);
        }

        return $created;
    }

    public function usesAutoCronFollowUp(FunnelStageAiRule $rule): bool
    {
        $strategy = trim((string) ($rule->follow_up_strategy ?? ''));

        if ($strategy === FunnelStageAiRule::FOLLOW_UP_STRATEGY_MANAGER_PROPOSALS) {
            return false;
        }

        if ($strategy === FunnelStageAiRule::FOLLOW_UP_STRATEGY_OFF) {
            return false;
        }

        return true;
    }

    public function advanceChatToNextStage(Chat $chat, int $fromStageId, int $triggerMessageId): bool
    {
        if ((int) $chat->funnel_stage_id !== $fromStageId) {
            return false;
        }

        $chat->loadMissing(['funnelStage.funnel.stages']);
        $nextStage = $this->stageSequence->nextStage($chat->funnelStage);
        if ($nextStage === null || $chat->funnel_id === null) {
            return false;
        }

        app(ChatFunnelStateService::class)->applyFromAi(
            $chat,
            new \App\Services\AI\ChatFunnelClassification(
                (int) $chat->funnel_id,
                (int) $nextStage->id,
                0.7,
                'Автодожим: клиент не ответил, чат переведён на этап «'.$nextStage->name.'».',
            ),
            $triggerMessageId,
        );

        return true;
    }

    public function cancelPendingForChat(Chat $chat, ?int $stageId = null): int
    {
        $query = ScheduledMessage::query()
            ->where('chat_id', $chat->id)
            ->where('purpose', ScheduledMessage::PURPOSE_FUNNEL_FOLLOW_UP)
            ->where('status', ScheduledMessage::STATUS_PENDING);

        if ($stageId !== null) {
            $query->where('funnel_stage_id', $stageId);
        }

        return $query->update([
            'status' => ScheduledMessage::STATUS_CANCELLED,
            'error' => null,
        ]);
    }

    private function scheduleForRule(FunnelStageAiRule $rule, int $limit): int
    {
        $stageId = (int) $rule->funnel_stage_id;
        $delayHours = max(1, (int) $rule->follow_up_delay_hours);
        $threshold = now()->subHours($delayHours);

        $created = 0;

        $this->eligibleChatsQuery($rule, $threshold)
            ->with(['whatsappSession', 'assignments', 'funnel.aiScenario'])
            ->orderBy('last_message_at')
            ->limit($limit)
            ->get()
            ->each(function (Chat $chat) use ($rule, $stageId, &$created): void {
                if ($this->scheduleOne($chat, $rule, $stageId)) {
                    $created++;
                }
            });

        return $created;
    }

    /**
     * @return Builder<Chat>
     */
    private function eligibleChatsQuery(FunnelStageAiRule $rule, Carbon $threshold): Builder
    {
        return app(ConsultationFollowUpEligibilityService::class)
            ->eligibleChatsQuery($rule, $threshold)
            ->whereDoesntHave('scheduledMessages', function (Builder $query) use ($rule): void {
                $query
                    ->where('purpose', ScheduledMessage::PURPOSE_FUNNEL_FOLLOW_UP)
                    ->where('funnel_stage_id', $rule->funnel_stage_id)
                    ->where('status', ScheduledMessage::STATUS_PENDING);
            });
    }

    private function scheduleOne(Chat $chat, FunnelStageAiRule $rule, int $stageId): bool
    {
        if ($this->followUpCountInCooldown($chat, $rule) >= max(1, (int) $rule->follow_up_max_count)) {
            return false;
        }

        $session = $chat->whatsappSession;
        $sender = $this->resolveSender($chat, $rule);
        if ($session === null || $sender === null) {
            return false;
        }

        $body = $this->renderMessage($rule, $chat);
        $scheduledAt = now()->addMinute();

        ScheduledMessage::query()->create([
            'chat_id' => $chat->id,
            'whatsapp_session_id' => $session->id,
            'user_id' => $sender->id,
            'purpose' => ScheduledMessage::PURPOSE_FUNNEL_FOLLOW_UP,
            'funnel_stage_id' => $stageId,
            'body' => $body,
            'display_body' => $body,
            'scheduled_at' => $scheduledAt,
            'status' => ScheduledMessage::STATUS_PENDING,
            'error' => null,
        ]);

        return true;
    }

    private function followUpCountInCooldown(Chat $chat, FunnelStageAiRule $rule): int
    {
        $cooldownHours = max(1, (int) $rule->follow_up_cooldown_hours);
        $since = now()->subHours($cooldownHours);

        return (int) ScheduledMessage::query()
            ->where('chat_id', $chat->id)
            ->where('funnel_stage_id', $rule->funnel_stage_id)
            ->where('purpose', ScheduledMessage::PURPOSE_FUNNEL_FOLLOW_UP)
            ->whereIn('status', [
                ScheduledMessage::STATUS_PENDING,
                ScheduledMessage::STATUS_SENDING,
                ScheduledMessage::STATUS_SENT,
            ])
            ->where('created_at', '>=', $since)
            ->count();
    }

    private function resolveSender(Chat $chat, FunnelStageAiRule $rule): ?User
    {
        if ($chat->ai_responder_user_id) {
            $user = User::query()->whereKey($chat->ai_responder_user_id)->where('is_active', true)->first();
            if ($user instanceof User) {
                return $user;
            }
        }

        $assignmentUserId = $chat->assignments()->value('user_id');
        if ($assignmentUserId) {
            $user = User::query()->whereKey($assignmentUserId)->where('is_active', true)->first();
            if ($user instanceof User) {
                return $user;
            }
        }

        $scenario = $chat->funnel?->aiScenario ?? $rule->funnel?->aiScenario;
        if ($scenario instanceof FunnelAiScenario && $scenario->fallback_manager_user_id) {
            return User::query()
                ->whereKey($scenario->fallback_manager_user_id)
                ->where('is_active', true)
                ->first();
        }

        return null;
    }

    private function renderMessage(FunnelStageAiRule $rule, Chat $chat): string
    {
        $mode = (string) ($rule->follow_up_mode ?? FunnelStageAiRule::FOLLOW_UP_MODE_TEMPLATE);

        if ($mode === FunnelStageAiRule::FOLLOW_UP_MODE_AI) {
            return $this->aiText->generate($rule, $chat);
        }

        $template = $this->pickTemplate($rule, $chat);
        if ($template === '') {
            $template = self::DEFAULT_MESSAGE;
        }

        $name = trim((string) ($chat->chat_name ?? ''));
        if ($name === '') {
            $name = 'клиент';
        }

        $nextStage = $this->stageSequence->nextStageForRule($rule);
        $nextStageName = trim((string) ($nextStage?->name ?? 'следующий этап'));
        $nextGoal = trim((string) ($this->stageSequence->nextStageRule($rule)?->goal ?? ''));

        $replacements = [
            '{chat_name}' => $name,
            '{client_name}' => $name,
            '{stage_name}' => (string) ($rule->stage?->name ?? 'этап'),
            '{next_stage_name}' => $nextStageName,
            '{next_stage_goal}' => $nextGoal !== '' ? $nextGoal : $nextStageName,
        ];

        $body = strtr($template, $replacements);

        return Str::limit($body, 4000, '');
    }

    private function pickTemplate(FunnelStageAiRule $rule, Chat $chat): string
    {
        $messageA = trim((string) ($rule->follow_up_message ?? ''));
        $messageB = trim((string) ($rule->follow_up_message_b ?? ''));
        $mode = (string) ($rule->follow_up_mode ?? FunnelStageAiRule::FOLLOW_UP_MODE_TEMPLATE);

        if ($mode === FunnelStageAiRule::FOLLOW_UP_MODE_AB && $messageB !== '') {
            $ratio = max(0, min(100, (int) ($rule->follow_up_ab_ratio ?? 50)));
            $bucket = abs(crc32((string) $chat->id.'|'.$rule->id)) % 100;

            return $bucket < $ratio ? $messageB : ($messageA !== '' ? $messageA : $messageB);
        }

        return $messageA;
    }
}
