<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\PhoneFormatter;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

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
        'metadata',
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
            'metadata' => 'array',
        ];
    }

    public function setSenderPhoneAttribute(?string $value): void
    {
        $this->attributes['sender_phone'] = PhoneFormatter::normalize($value);
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

    public function transcript(): HasOne
    {
        return $this->hasOne(MessageTranscript::class);
    }

    public function reactions(): HasMany
    {
        return $this->hasMany(MessageReaction::class);
    }

    public function aiRatings(): HasMany
    {
        return $this->hasMany(AiMessageRating::class);
    }

    /**
     * Цитируемое сообщение. На отправке мы сохраняем WA-идентификатор
     * оригинала в `quoted_message_id`, поэтому резолвим self-FK именно по нему.
     * Может вернуть null, если оригинал удалили или находится в соседнем чате.
     */
    public function quotedMessage(): BelongsTo
    {
        return $this->belongsTo(self::class, 'quoted_message_id', 'whatsapp_message_id');
    }
}
