<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class MessageReaction extends Model
{
    protected $fillable = [
        'message_id',
        'user_id',
        'external_id',
        'external_name',
        'emoji',
        'pending_whatsapp_sync',
        'whatsapp_synced_at',
        'whatsapp_sync_error',
    ];

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
