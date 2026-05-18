<?php

declare(strict_types=1);

namespace App\Services\AI;

use Illuminate\Support\Str;

final readonly class AiFunnelOrchestratorPlan
{
    /**
     * @param  array<string, mixed>|null  $appointment
     * @param  array<string, mixed>|null  $task
     */
    public function __construct(
        public ?string $customerReply,
        public ?int $targetFunnelStageId,
        public ?array $appointment,
        public ?int $assigneeUserId,
        public ?string $managerNote,
        public ?array $task,
        public bool $requiresManagerAttention,
        public float $confidence,
        public string $reason,
    ) {}

    /**
     * @param  array<string, mixed>  $raw
     */
    public static function fromArray(array $raw): self
    {
        $reply = self::nullableString($raw['customer_reply'] ?? null, 4000);
        $stageId = isset($raw['target_funnel_stage_id']) && (int) $raw['target_funnel_stage_id'] > 0
            ? (int) $raw['target_funnel_stage_id']
            : null;
        $assigneeId = isset($raw['assignee_user_id']) && (int) $raw['assignee_user_id'] > 0
            ? (int) $raw['assignee_user_id']
            : null;
        $appointment = is_array($raw['appointment_request'] ?? null) ? $raw['appointment_request'] : null;
        $task = is_array($raw['task'] ?? null) ? $raw['task'] : null;
        $managerNote = self::nullableString($raw['manager_note'] ?? null, 4000);
        $confidence = max(0.0, min(1.0, (float) ($raw['confidence'] ?? 0)));
        $reason = self::nullableString($raw['reason'] ?? null, 500) ?? 'AI-оркестратор обработал диалог.';

        return new self(
            customerReply: $reply,
            targetFunnelStageId: $stageId,
            appointment: $appointment,
            assigneeUserId: $assigneeId,
            managerNote: $managerNote,
            task: $task,
            requiresManagerAttention: filter_var($raw['requires_manager_attention'] ?? false, FILTER_VALIDATE_BOOLEAN),
            confidence: $confidence,
            reason: $reason,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'customer_reply' => $this->customerReply,
            'target_funnel_stage_id' => $this->targetFunnelStageId,
            'appointment_request' => $this->appointment,
            'assignee_user_id' => $this->assigneeUserId,
            'manager_note' => $this->managerNote,
            'task' => $this->task,
            'requires_manager_attention' => $this->requiresManagerAttention,
            'confidence' => $this->confidence,
            'reason' => $this->reason,
        ];
    }

    private static function nullableString(mixed $value, int $limit): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        return Str::limit($value, $limit, '…');
    }
}
