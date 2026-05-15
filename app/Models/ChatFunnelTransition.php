<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ChatFunnelTransition extends Model
{
    public const UPDATED_AT = null;

    public const SOURCE_AI = 'ai';

    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_SYSTEM = 'system';

    protected $fillable = [
        'chat_id',
        'company_id',
        'from_funnel_id',
        'from_stage_id',
        'to_funnel_id',
        'to_stage_id',
        'source',
        'confidence',
        'reason',
        'trigger_message_id',
    ];

    protected function casts(): array
    {
        return [
            'chat_id' => 'integer',
            'company_id' => 'integer',
            'confidence' => 'float',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Chat, $this>
     */
    public function chat(): BelongsTo
    {
        return $this->belongsTo(Chat::class);
    }

    /**
     * @return BelongsTo<Funnel, $this>
     */
    public function toFunnel(): BelongsTo
    {
        return $this->belongsTo(Funnel::class, 'to_funnel_id');
    }

    /**
     * @return BelongsTo<FunnelStage, $this>
     */
    public function toStage(): BelongsTo
    {
        return $this->belongsTo(FunnelStage::class, 'to_stage_id');
    }
}
