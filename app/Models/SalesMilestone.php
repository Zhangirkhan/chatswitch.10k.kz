<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class SalesMilestone extends Model
{
    public const MILESTONE_FIRST_INBOUND = 'first_inbound';

    public const MILESTONE_QUALIFIED = 'qualified';

    public const MILESTONE_BUDGET_CAPTURED = 'budget_captured';

    public const MILESTONE_DM_CAPTURED = 'dm_captured';

    public const MILESTONE_TIMELINE_CAPTURED = 'timeline_captured';

    public const MILESTONE_REQUIREMENTS_CAPTURED = 'requirements_captured';

    public const MILESTONE_PROPOSAL_SENT = 'proposal_sent';

    public const MILESTONE_MEETING_BOOKED = 'meeting_booked';

    public const MILESTONE_DEFERRAL = 'deferral';

    public const MILESTONE_RE_ENGAGED = 're_engaged';

    public const MILESTONE_CLOSED_WON = 'closed_won';

    public const MILESTONE_CLOSED_LOST = 'closed_lost';

    public const SOURCE_AI = 'ai';

    public const SOURCE_SYSTEM = 'system';

    public const SOURCE_MANAGER = 'manager';

    public const SOURCE_ORCHESTRATOR = 'orchestrator';

    protected $fillable = [
        'company_id',
        'chat_id',
        'contact_id',
        'milestone',
        'source',
        'trigger_message_id',
        'payload',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'chat_id' => 'integer',
            'contact_id' => 'integer',
            'trigger_message_id' => 'integer',
            'payload' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Chat, $this> */
    public function chat(): BelongsTo
    {
        return $this->belongsTo(Chat::class);
    }
}
