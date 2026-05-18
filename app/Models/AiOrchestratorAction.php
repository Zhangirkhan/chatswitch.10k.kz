<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class AiOrchestratorAction extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_DONE = 'done';

    public const STATUS_SKIPPED = 'skipped';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'ai_orchestrator_run_id',
        'company_id',
        'chat_id',
        'type',
        'status',
        'payload',
        'result',
        'error',
        'message_id',
        'calendar_event_id',
        'assigned_user_id',
        'team_message_id',
    ];

    protected function casts(): array
    {
        return [
            'ai_orchestrator_run_id' => 'integer',
            'company_id' => 'integer',
            'chat_id' => 'integer',
            'payload' => 'array',
            'result' => 'array',
            'message_id' => 'integer',
            'calendar_event_id' => 'integer',
            'assigned_user_id' => 'integer',
            'team_message_id' => 'integer',
        ];
    }

    /** @return BelongsTo<AiOrchestratorRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(AiOrchestratorRun::class, 'ai_orchestrator_run_id');
    }
}
