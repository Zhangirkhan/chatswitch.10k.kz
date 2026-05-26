<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\AiResponseLog;
use App\Models\Chat;
use App\Models\Department;
use App\Models\Message;
use App\Services\Department\DepartmentWorkScheduleService;
use App\Services\OutboundChatMessageDispatcher;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

final class ChatOffHoursReplyService
{
    public function __construct(
        private readonly DepartmentWorkScheduleService $schedules,
        private readonly AiResponderResolver $responderResolver,
        private readonly OutboundChatMessageDispatcher $dispatcher,
    ) {}

    /**
     * Отправляет вежливый автоответ вне графика выбранного отдела.
     * Отдел нужно сначала определить через {@see ChatDepartmentRoutingService::resolveAndAssignDepartment()}.
     * true — дальнейшую AI-обработку пропускаем.
     */
    public function tryReply(Chat $chat, Message $trigger, ?Department $department, ?CarbonInterface $at = null): bool
    {
        if ($chat->is_group || $trigger->direction !== 'inbound' || ! $chat->ai_enabled) {
            return false;
        }

        if (! $department instanceof Department) {
            return false;
        }

        if ($this->schedules->isDepartmentOpen($department, $at)) {
            return false;
        }

        if ($this->alreadyRepliedForTrigger($chat, $trigger)) {
            return true;
        }

        if ($this->isRateLimited($chat, $department, $at)) {
            return true;
        }

        $responder = $this->responderResolver->forChat($chat, $chat->funnel?->aiScenario);
        if ($responder === null) {
            Log::warning('[off-hours] no responder for chat', ['chat_id' => $chat->id]);

            return true;
        }

        $reply = $this->schedules->buildOffHoursReply($department, $at);

        $message = $this->dispatcher->sendTextMessage($responder, $chat, [
            'message' => $reply,
            'display_message' => $reply,
            'metadata' => [
                'ai' => [
                    'generated' => true,
                    'mode' => 'off_hours',
                    'trigger_message_id' => $trigger->id,
                    'department_id' => $department->id,
                    'reply_as_company' => $this->responderResolver->replyAsCompany($chat),
                ],
            ],
        ])->message;

        AiResponseLog::query()->updateOrCreate(
            [
                'trigger_message_id' => $trigger->id,
                'mode' => 'auto',
            ],
            [
                'company_id' => $chat->company_id ?? $responder->company_id,
                'chat_id' => $chat->id,
                'user_id' => $responder->id,
                'message_id' => $message->id,
                'status' => 'sent',
                'metadata' => [
                    'off_hours' => true,
                    'department_id' => $department->id,
                ],
            ],
        );

        $this->hitRateLimit($chat, $department, $at);

        return true;
    }

    private function alreadyRepliedForTrigger(Chat $chat, Message $trigger): bool
    {
        return AiResponseLog::query()
            ->where('chat_id', $chat->id)
            ->where('trigger_message_id', $trigger->id)
            ->where('mode', 'auto')
            ->whereIn('status', ['sent', 'generating', 'pending'])
            ->exists();
    }

    private function isRateLimited(Chat $chat, Department $department, ?CarbonInterface $at): bool
    {
        return RateLimiter::tooManyAttempts($this->rateKey($chat, $department), 1);
    }

    private function hitRateLimit(Chat $chat, Department $department, ?CarbonInterface $at): void
    {
        RateLimiter::hit($this->rateKey($chat, $department), $this->rateDecaySeconds($department, $at));
    }

    private function rateKey(Chat $chat, Department $department): string
    {
        return 'dept-off-hours:'.$chat->id.':'.$department->id;
    }

    private function rateDecaySeconds(Department $department, ?CarbonInterface $at): int
    {
        $schedule = \App\Support\DepartmentWorkSchedule::fromDepartment($department);
        if ($schedule === null) {
            return 3600;
        }

        $next = $schedule->nextOpenAt($at ?? now());
        if ($next === null) {
            return 3600;
        }

        $seconds = (int) max(300, $next->diffInSeconds($at ?? now(), false));

        return min($seconds, 86400);
    }
}
