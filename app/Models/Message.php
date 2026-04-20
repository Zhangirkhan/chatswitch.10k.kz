<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Message extends Model
{
    use HasFactory;

    protected $fillable = [
        'chat_id',
        'whatsapp_session_id',
        'whatsapp_message_id',
        'direction',
        'type',
        'body',
        'sender_phone',
        'sender_name',
        'sent_by_user_id',
        'is_forwarded',
        'quoted_message_id',
        'ack',
        'message_timestamp',
    ];

    protected function casts(): array
    {
        return [
            'is_forwarded' => 'boolean',
            'message_timestamp' => 'datetime',
        ];
    }

    public function chat(): BelongsTo
    {
        return $this->belongsTo(Chat::class);
    }

    public function whatsappSession(): BelongsTo
    {
        return $this->belongsTo(WhatsappSession::class);
    }

    public function sentByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by_user_id');
    }

    public function media(): HasMany
    {
        return $this->hasMany(MessageMedia::class);
    }

    public function reactions(): HasMany
    {
        return $this->hasMany(MessageReaction::class);
    }
}
