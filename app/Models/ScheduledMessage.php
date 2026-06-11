<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ScheduledMessage extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_SENDING = 'sending';

    public const STATUS_SENT = 'sent';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_FAILED = 'failed';

    public const PURPOSE_APPOINTMENT_REMINDER = 'appointment_reminder';

    public const PURPOSE_FUNNEL_FOLLOW_UP = 'funnel_follow_up';

    public const PURPOSE_NURTURE_FOLLOW_UP = 'nurture_follow_up';

    protected $fillable = [
        'chat_id',
        'whatsapp_session_id',
        'user_id',
        'calendar_event_id',
        'purpose',
        'funnel_stage_id',
        'body',
        'display_body',
        'scheduled_at',
        'status',
        'sent_message_id',
        'error',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'funnel_stage_id' => 'integer',
            'metadata' => 'array',
        ];
    }

    public function funnelStage(): BelongsTo
    {
        return $this->belongsTo(FunnelStage::class, 'funnel_stage_id');
    }

    public function chat(): BelongsTo
    {
        return $this->belongsTo(Chat::class);
    }

    public function whatsappSession(): BelongsTo
    {
        return $this->belongsTo(WhatsappSession::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function calendarEvent(): BelongsTo
    {
        return $this->belongsTo(CalendarEvent::class);
    }

    public function sentMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'sent_message_id');
    }
}
