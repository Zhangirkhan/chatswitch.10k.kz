<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class AiOrchestratorRun extends Model
{
    use HasFactory;
    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_NEEDS_MANAGER = 'needs_manager';

    public const STATUS_SKIPPED = 'skipped';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'company_id',
        'chat_id',
        'trigger_message_id',
        'funnel_id',
        'funnel_stage_id',
        'status',
        'confidence',
        'reason',
        'context',
        'plan',
        'error',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'chat_id' => 'integer',
            'trigger_message_id' => 'integer',
            'funnel_id' => 'integer',
            'funnel_stage_id' => 'integer',
            'confidence' => 'float',
            'context' => 'array',
            'plan' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Chat, $this> */
    public function chat(): BelongsTo
    {
        return $this->belongsTo(Chat::class);
    }

    /** @return BelongsTo<Message, $this> */
    public function triggerMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'trigger_message_id');
    }

    /** @return HasMany<AiOrchestratorAction, $this> */
    public function actions(): HasMany
    {
        return $this->hasMany(AiOrchestratorAction::class);
    }
}
