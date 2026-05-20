<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\AiOrchestratorAction;
use App\Models\AiOrchestratorRun;
use App\Models\CalendarEvent;
use App\Models\Chat;
use App\Models\ChatAssignment;
use App\Models\Department;
use App\Models\DepartmentPost;
use App\Models\FunnelAiScenario;
use App\Models\FunnelStageAiRule;
use App\Models\Message;
use App\Models\User;
use App\Services\Calendar\AppointmentBookingService;
use App\Services\Calendar\ChatAssignmentCalendarSyncService;
use App\Services\ChatService;
use App\Services\Funnel\ChatFunnelStateService;
use App\Services\Funnel\FunnelStageTransitionGuard;
use App\Services\OutboundChatMessageDispatcher;
use App\Services\TeamChatService;
use App\Services\TeamDepartmentChatSyncService;
use Carbon\Carbon;
use Throwable;

final class AiFunnelActionExecutor
{
    public function __construct(
        private readonly OutboundChatMessageDispatcher $dispatcher,
        private readonly ChatFunnelStateService $funnelState,
        private readonly AppointmentBookingService $booking,
        private readonly ChatService $chatService,
        private readonly TeamChatService $teamChatService,
        private readonly TeamDepartmentChatSyncService $teamDepartmentChatSync,
        private readonly FunnelStageTransitionGuard $stageTransitionGuard,
        private readonly ChatAssignmentCalendarSyncService $assignmentCalendarSync,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function execute(
        AiOrchestratorRun $run,
        Chat $chat,
        Message $trigger,
        User $actor,
        FunnelAiScenario $scenario,
        ?FunnelStageAiRule $rule,
        AiFunnelOrchestratorPlan $plan,
    ): array {
        $allowed = $this->allowedActions($rule);
        $result = [];

        if ($plan->customerReply !== null && $chat->ai_enabled) {
            $result['reply_customer'] = $this->runAction(
                $run,
                $chat,
                FunnelStageAiRule::ACTION_REPLY_CUSTOMER,
                ['body' => $plan->customerReply],
                fn (AiOrchestratorAction $action): array => $this->sendCustomerReply($action, $actor, $chat, $trigger, $plan->customerReply),
                $allowed,
            );
        }

        if ($plan->appointment !== null) {
            $result['create_appointment'] = $this->runAction(
                $run,
                $chat,
                FunnelStageAiRule::ACTION_CREATE_APPOINTMENT,
                $plan->appointment,
                fn (AiOrchestratorAction $action): array => $this->createAppointment($action, $actor, $chat, $trigger, $plan),
                $allowed,
            );
        }

        if ($plan->assigneeUserId !== null) {
            $result['assign_employee'] = $this->runAction(
                $run,
                $chat,
                FunnelStageAiRule::ACTION_ASSIGN_EMPLOYEE,
                ['user_id' => $plan->assigneeUserId],
                fn (AiOrchestratorAction $action): array => $this->assignEmployee($action, $actor, $chat, $plan->assigneeUserId),
                $allowed,
            );
        }

        if ($plan->targetFunnelStageId !== null && $chat->funnel_id !== null) {
            $result['move_funnel_stage'] = $this->runAction(
                $run,
                $chat,
                FunnelStageAiRule::ACTION_MOVE_FUNNEL_STAGE,
                ['funnel_stage_id' => $plan->targetFunnelStageId],
                fn (): array => $this->moveFunnelStage($chat, $trigger, $plan),
                $allowed,
            );
        }

        if ($plan->managerNote !== null || $plan->requiresManagerAttention) {
            $note = $plan->managerNote ?: 'AI-оркестратору нужна помощь менеджера по этому диалогу.';
            $result['notify_manager'] = $this->runAction(
                $run,
                $chat,
                FunnelStageAiRule::ACTION_NOTIFY_MANAGER,
                ['note' => $note],
                fn (AiOrchestratorAction $action): array => $this->notifyManager($action, $actor, $chat, $scenario, $note),
                $allowed,
            );
        }

        if (is_array($plan->task)) {
            $result['create_task'] = $this->runAction(
                $run,
                $chat,
                FunnelStageAiRule::ACTION_CREATE_TASK,
                $plan->task,
                fn (): array => $this->createTask($actor, $scenario, $plan),
                $allowed,
            );
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $allowed
     * @return array<string, mixed>
     */
    private function runAction(
        AiOrchestratorRun $run,
        Chat $chat,
        string $type,
        array $payload,
        callable $callback,
        array $allowed,
    ): array {
        $action = AiOrchestratorAction::query()->firstOrCreate(
            [
                'ai_orchestrator_run_id' => $run->id,
                'type' => $type,
            ],
            [
                'company_id' => $chat->company_id,
                'chat_id' => $chat->id,
                'status' => AiOrchestratorAction::STATUS_PENDING,
                'payload' => $payload,
            ],
        );

        if ($action->status === AiOrchestratorAction::STATUS_DONE) {
            return $action->result ?? [];
        }

        if (! in_array($type, $allowed, true)) {
            $action->forceFill([
                'status' => AiOrchestratorAction::STATUS_SKIPPED,
                'error' => 'Действие не разрешено правилами этапа.',
            ])->save();

            return ['skipped' => true];
        }

        try {
            /** @var array<string, mixed> $result */
            $result = $callback($action);
            $action->forceFill([
                'status' => AiOrchestratorAction::STATUS_DONE,
                'result' => $result,
                'error' => null,
            ])->save();

            return $result;
        } catch (Throwable $e) {
            $action->forceFill([
                'status' => AiOrchestratorAction::STATUS_FAILED,
                'error' => mb_substr($e->getMessage(), 0, 2000),
            ])->save();

            throw $e;
        }
    }

    /** @return list<string> */
    private function allowedActions(?FunnelStageAiRule $rule): array
    {
        $configured = $rule?->allowed_actions;
        if (! is_array($configured) || $configured === []) {
            return FunnelStageAiRule::DEFAULT_ALLOWED_ACTIONS;
        }

        return array_values(array_filter($configured, 'is_string'));
    }

    /** @return array<string, mixed> */
    private function sendCustomerReply(AiOrchestratorAction $action, User $actor, Chat $chat, Message $trigger, string $body): array
    {
        $message = $this->dispatcher->sendTextMessage($actor, $chat, [
            'message' => $body,
            'display_message' => $body,
            'quoted_message_id' => $trigger->whatsapp_message_id,
            'metadata' => [
                'ai' => [
                    'generated' => true,
                    'mode' => 'orchestrator',
                    'trigger_message_id' => $trigger->id,
                    'orchestrator_action_id' => $action->id,
                ],
            ],
        ])->message;

        $action->forceFill(['message_id' => $message->id])->save();

        return ['message_id' => $message->id];
    }

    /** @return array<string, mixed> */
    private function createAppointment(
        AiOrchestratorAction $action,
        User $actor,
        Chat $chat,
        Message $trigger,
        AiFunnelOrchestratorPlan $plan,
    ): array {
        $appointment = $plan->appointment ?? [];
        $serviceName = trim((string) ($appointment['service_name'] ?? 'Запись'));
        $startsAt = Carbon::parse((string) ($appointment['starts_at'] ?? ''));
        $duration = max(1, (int) ($appointment['duration_minutes'] ?? 60));
        $reminderLeadMinutes = isset($appointment['reminder_lead_minutes']) && is_numeric($appointment['reminder_lead_minutes'])
            ? (int) $appointment['reminder_lead_minutes']
            : null;
        $assignee = $this->resolveAppointmentAssignee($chat, $plan, $actor);

        $existing = CalendarEvent::query()
            ->where('chat_id', $chat->id)
            ->where('source', CalendarEvent::SOURCE_AI_AUTO)
            ->where('starts_at', '>=', now()->subDay())
            ->orderByDesc('starts_at')
            ->first();

        if ($existing instanceof CalendarEvent) {
            $metadata = $existing->metadata ?? [];
            $aiMeta = is_array($metadata['ai'] ?? null) ? $metadata['ai'] : [];
            if ($reminderLeadMinutes !== null) {
                $aiMeta['reminder_lead_minutes'] = $reminderLeadMinutes;
            }

            $existing->forceFill([
                'user_id' => $actor->id,
                'assignee_user_id' => $assignee->id,
                'trigger_message_id' => $trigger->id,
                'title' => $this->eventTitle($serviceName, $chat),
                'description' => $this->eventDescription($serviceName, $chat, $appointment['client_note'] ?? null),
                'starts_at' => $startsAt,
                'ends_at' => $startsAt->copy()->addMinutes($duration),
                'metadata' => [
                    ...$metadata,
                    'ai' => [
                        ...$aiMeta,
                        'generated' => true,
                        'rescheduled' => true,
                        'service_name' => $serviceName,
                        'duration_minutes' => $duration,
                        'trigger_message_id' => $trigger->id,
                        'assignee_user_id' => $assignee->id,
                    ],
                ],
            ])->save();

            $this->booking->syncReminderForEvent(
                $existing,
                $chat,
                $actor,
                $serviceName,
                $startsAt,
                $reminderLeadMinutes,
            );

            $this->assignmentCalendarSync->ensureChatAssignment($chat, $assignee->id, $actor->id);

            $action->forceFill(['calendar_event_id' => $existing->id])->save();

            return ['calendar_event_id' => $existing->id, 'rescheduled' => true];
        }

        $booked = $this->booking->book(
            $chat,
            $actor,
            $trigger,
            $serviceName,
            $startsAt,
            $duration,
            isset($appointment['client_note']) ? (string) $appointment['client_note'] : null,
            $assignee,
            $reminderLeadMinutes,
        );

        $event = $booked['event'];
        $this->assignmentCalendarSync->ensureChatAssignment($chat, $assignee->id, $actor->id);
        $action->forceFill(['calendar_event_id' => $event->id])->save();

        return ['calendar_event_id' => $event->id];
    }

    private function resolveAppointmentAssignee(Chat $chat, AiFunnelOrchestratorPlan $plan, User $actor): User
    {
        if ($plan->assigneeUserId !== null) {
            $fromPlan = User::query()->whereKey($plan->assigneeUserId)->where('is_active', true)->first();
            if ($fromPlan instanceof User) {
                return $fromPlan;
            }
        }

        $fromChat = $this->assignmentCalendarSync->primaryAssigneeFromChat($chat);
        if ($fromChat !== null) {
            $user = User::query()->whereKey($fromChat)->where('is_active', true)->first();
            if ($user instanceof User) {
                return $user;
            }
        }

        return $actor;
    }

    private function eventTitle(string $serviceName, Chat $chat): string
    {
        $client = trim((string) ($chat->chat_name ?: $chat->contact?->name));

        return $client !== '' ? "{$serviceName} — {$client}" : $serviceName;
    }

    private function eventDescription(string $serviceName, Chat $chat, mixed $clientNote): string
    {
        $parts = [
            'Запись обновлена AI из WhatsApp-чата.',
            "Услуга: {$serviceName}.",
        ];

        $note = trim((string) $clientNote);
        if ($note !== '') {
            $parts[] = 'Комментарий клиента: '.$note;
        }

        if ($chat->contact?->phone_number) {
            $parts[] = 'Телефон: '.$chat->contact->phone_number.'.';
        }

        return implode("\n", $parts);
    }

    /** @return array<string, mixed> */
    private function assignEmployee(AiOrchestratorAction $action, User $actor, Chat $chat, int $userId): array
    {
        $oldIds = $chat->assignments()->pluck('user_id')->map(fn ($id) => (int) $id)->all();
        ChatAssignment::query()->firstOrCreate(
            ['chat_id' => $chat->id, 'user_id' => $userId],
            ['assigned_by' => $actor->id],
        );
        $newIds = $chat->assignments()->pluck('user_id')->map(fn ($id) => (int) $id)->all();
        $this->assignmentCalendarSync->syncFromAssignmentChange($chat, $oldIds, $newIds);
        $this->chatService->logAssignmentChange($chat, $actor, $oldIds, $newIds);
        $action->forceFill(['assigned_user_id' => $userId])->save();

        return ['assigned_user_id' => $userId];
    }

    /** @return array<string, mixed> */
    private function moveFunnelStage(Chat $chat, Message $trigger, AiFunnelOrchestratorPlan $plan): array
    {
        $funnelId = (int) $chat->funnel_id;
        $stageId = (int) $plan->targetFunnelStageId;

        if (! $this->stageTransitionGuard->canMove($chat, $funnelId, $stageId, $plan->confidence)) {
            return [
                'skipped' => true,
                'reason' => $this->stageTransitionGuard->rejectReason($chat, $funnelId, $stageId, $plan->confidence),
            ];
        }

        $this->funnelState->applyFromAi(
            $chat,
            new ChatFunnelClassification($funnelId, $stageId, $plan->confidence, $plan->reason),
            $trigger->id,
        );

        return [
            'funnel_id' => $funnelId,
            'funnel_stage_id' => $stageId,
        ];
    }

    /** @return array<string, mixed> */
    private function notifyManager(
        AiOrchestratorAction $action,
        User $actor,
        Chat $chat,
        FunnelAiScenario $scenario,
        string $note,
    ): array {
        $message = $this->chatService->logSystemMessage($chat, 'AI-оркестратор: '.$note);
        $teamMessage = null;
        $department = $scenario->fallbackDepartment;
        if ($department instanceof Department) {
            $conversation = $this->teamDepartmentChatSync->ensureDepartmentConversation($department);
            $teamMessage = $this->teamChatService->sendMessage($actor, $conversation, $this->managerTeamBody($chat, $note))->message;
            $action->forceFill(['team_message_id' => $teamMessage->id])->save();
        }

        return [
            'message_id' => $message->id,
            'team_message_id' => $teamMessage?->id,
        ];
    }

    /** @return array<string, mixed> */
    private function createTask(User $actor, FunnelAiScenario $scenario, AiFunnelOrchestratorPlan $plan): array
    {
        $department = $scenario->fallbackDepartment;
        if (! $department instanceof Department) {
            return ['skipped' => true, 'reason' => 'fallback_department_id is not configured'];
        }

        $task = $plan->task ?? [];
        $post = DepartmentPost::query()->create([
            'department_id' => $department->id,
            'author_id' => $actor->id,
            'title' => mb_substr(trim((string) ($task['title'] ?? 'AI-оркестратор: нужна помощь')), 0, 255),
            'body' => trim((string) ($task['body'] ?? $plan->managerNote ?? 'Нужно проверить диалог клиента.')),
            'status' => DepartmentPost::STATUS_OPEN,
            'due_at' => null,
        ]);

        if ($scenario->fallback_manager_user_id !== null) {
            $post->assignees()->sync([$scenario->fallback_manager_user_id]);
        }

        return ['department_post_id' => $post->id];
    }

    private function managerTeamBody(Chat $chat, string $note): string
    {
        $url = route('chats.show', $chat);

        return "AI-оркестратор просит проверить клиентский чат:\n{$note}\n\n{$url}";
    }
}
