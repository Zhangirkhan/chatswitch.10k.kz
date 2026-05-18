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
    public const DEFAULT_MESSAGE = 'Добрый день! Вы ещё рассматриваете наше предложение? Если остались вопросы — напишите, будем рады помочь.';

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
            ->where('follow_up_enabled', true)
            ->with(['stage:id,funnel_id,name', 'funnel.aiScenario'])
            ->orderBy('id')
            ->get();

        foreach ($rules as $rule) {
            if ($created >= $limit) {
                break;
            }

            if (! $rule instanceof FunnelStageAiRule || $rule->stage === null) {
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
        return Chat::query()
            ->where('company_id', $rule->company_id)
            ->where('funnel_id', $rule->funnel_id)
            ->where('funnel_stage_id', $rule->funnel_stage_id)
            ->where('funnel_tracking_enabled', true)
            ->where('is_archived', false)
            ->where('is_group', false)
            ->where('last_message_direction', 'inbound')
            ->whereNotNull('last_message_at')
            ->where('last_message_at', '<=', $threshold)
            ->whereDoesntHave('messages', function (Builder $query): void {
                $query
                    ->where('direction', 'outbound')
                    ->whereColumn('message_timestamp', '>', 'chats.last_message_at');
            })
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
        $template = trim((string) ($rule->follow_up_message ?? ''));
        if ($template === '') {
            $template = self::DEFAULT_MESSAGE;
        }

        $name = trim((string) ($chat->chat_name ?? ''));
        if ($name === '') {
            $name = 'клиент';
        }

        $replacements = [
            '{chat_name}' => $name,
            '{client_name}' => $name,
            '{stage_name}' => (string) ($rule->stage?->name ?? 'этап'),
        ];

        $body = strtr($template, $replacements);

        return Str::limit($body, 4000, '');
    }
}
