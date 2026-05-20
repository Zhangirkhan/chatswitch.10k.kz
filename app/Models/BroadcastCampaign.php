<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class BroadcastCampaign extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public const SOURCE_EXCEL = 'excel';

    public const SOURCE_FILTERS = 'filters';

    protected $fillable = [
        'created_by_user_id',
        'sender_user_id',
        'whatsapp_session_id',
        'source',
        'status',
        'delay_seconds',
        'filter_message',
        'filters',
        'total_rows',
        'ready_count',
        'sent_count',
        'skipped_count',
        'failed_count',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'delay_seconds' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_user_id');
    }

    public function whatsappSession(): BelongsTo
    {
        return $this->belongsTo(WhatsappSession::class);
    }

    /** @return HasMany<BroadcastCampaignItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(BroadcastCampaignItem::class);
    }
}
