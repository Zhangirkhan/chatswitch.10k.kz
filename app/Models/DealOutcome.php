<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class DealOutcome extends Model
{
    public const SOURCE_AUTO_STAGE = 'auto_stage';

    public const SOURCE_MANUAL_CLOSE = 'manual_close';

    public const SOURCE_MANAGER = 'manager';

    protected $fillable = [
        'company_id',
        'chat_id',
        'contact_id',
        'won',
        'reason',
        'industry',
        'lead_score',
        'lead_grade',
        'sales_state_snapshot',
        'objections_at_close',
        'funnel_stage_id',
        'source',
        'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'chat_id' => 'integer',
            'contact_id' => 'integer',
            'won' => 'boolean',
            'lead_score' => 'integer',
            'sales_state_snapshot' => 'array',
            'funnel_stage_id' => 'integer',
            'closed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Chat, $this> */
    public function chat(): BelongsTo
    {
        return $this->belongsTo(Chat::class);
    }

    /** @return BelongsTo<Contact, $this> */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }
}
