<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class FollowUpOutcome extends Model
{
    protected $fillable = [
        'scheduled_message_id',
        'chat_id',
        'responded_at',
        'recovered_to_qualified',
        'deal_outcome_id',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_message_id' => 'integer',
            'chat_id' => 'integer',
            'responded_at' => 'datetime',
            'recovered_to_qualified' => 'boolean',
            'deal_outcome_id' => 'integer',
        ];
    }

    /** @return BelongsTo<ScheduledMessage, $this> */
    public function scheduledMessage(): BelongsTo
    {
        return $this->belongsTo(ScheduledMessage::class);
    }
}
