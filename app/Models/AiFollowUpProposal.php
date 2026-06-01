<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class AiFollowUpProposal extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_NEEDS_MANAGER = 'needs_manager';

    public const STATUS_SENT = 'sent';

    public const STATUS_DISMISSED = 'dismissed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'company_id',
        'chat_id',
        'funnel_id',
        'funnel_stage_id',
        'trigger_message_id',
        'status',
        'proposals',
        'recommended_id',
        'manager_note',
        'context_summary',
        'selected_variant_id',
        'sent_message_id',
        'created_by_user_id',
        'error',
        'dismissed_at',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'chat_id' => 'integer',
            'funnel_id' => 'integer',
            'funnel_stage_id' => 'integer',
            'trigger_message_id' => 'integer',
            'proposals' => 'array',
            'sent_message_id' => 'integer',
            'created_by_user_id' => 'integer',
            'dismissed_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Chat, $this> */
    public function chat(): BelongsTo
    {
        return $this->belongsTo(Chat::class);
    }

    /** @return BelongsTo<FunnelStage, $this> */
    public function funnelStage(): BelongsTo
    {
        return $this->belongsTo(FunnelStage::class);
    }

    /** @return BelongsTo<Message, $this> */
    public function sentMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'sent_message_id');
    }
}
