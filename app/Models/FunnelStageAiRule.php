<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class FunnelStageAiRule extends Model
{
    public const ACTION_REPLY_CUSTOMER = 'reply_customer';

    public const ACTION_MOVE_FUNNEL_STAGE = 'move_funnel_stage';

    public const ACTION_CREATE_APPOINTMENT = 'create_appointment';

    public const ACTION_ASSIGN_EMPLOYEE = 'assign_employee';

    public const ACTION_NOTIFY_MANAGER = 'notify_manager';

    public const ACTION_CREATE_TASK = 'create_task';

    public const DEFAULT_ALLOWED_ACTIONS = [
        self::ACTION_REPLY_CUSTOMER,
        self::ACTION_MOVE_FUNNEL_STAGE,
        self::ACTION_CREATE_APPOINTMENT,
        self::ACTION_ASSIGN_EMPLOYEE,
        self::ACTION_NOTIFY_MANAGER,
        self::ACTION_CREATE_TASK,
    ];

    protected $fillable = [
        'company_id',
        'funnel_id',
        'funnel_stage_id',
        'goal',
        'required_questions',
        'transition_conditions',
        'allowed_actions',
        'assignee_user_ids',
        'assignee_department_id',
        'require_manager_confirmation',
        'follow_up_enabled',
        'follow_up_delay_hours',
        'follow_up_message',
        'follow_up_mode',
        'follow_up_message_b',
        'follow_up_ab_ratio',
        'follow_up_cooldown_hours',
        'follow_up_max_count',
    ];

    public const FOLLOW_UP_MODE_TEMPLATE = 'template';

    public const FOLLOW_UP_MODE_AB = 'ab';

    public const FOLLOW_UP_MODE_AI = 'ai';

    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'funnel_id' => 'integer',
            'funnel_stage_id' => 'integer',
            'required_questions' => 'array',
            'allowed_actions' => 'array',
            'assignee_user_ids' => 'array',
            'assignee_department_id' => 'integer',
            'require_manager_confirmation' => 'boolean',
            'follow_up_enabled' => 'boolean',
            'follow_up_delay_hours' => 'integer',
            'follow_up_ab_ratio' => 'integer',
            'follow_up_cooldown_hours' => 'integer',
            'follow_up_max_count' => 'integer',
        ];
    }

    /** @return BelongsTo<Funnel, $this> */
    public function funnel(): BelongsTo
    {
        return $this->belongsTo(Funnel::class);
    }

    /** @return BelongsTo<FunnelStage, $this> */
    public function stage(): BelongsTo
    {
        return $this->belongsTo(FunnelStage::class, 'funnel_stage_id');
    }

    /** @return BelongsTo<Department, $this> */
    public function assigneeDepartment(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'assignee_department_id');
    }
}
