<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

final class Chat extends Model
{
    use HasFactory;

    protected $fillable = [
        'whatsapp_chat_id',
        'whatsapp_session_id',
        'contact_id',
        'community_id',
        'chat_name',
        'is_group',
        'last_message_text',
        'last_message_at',
        'last_message_direction',
        'unread_count',
        'is_archived',
        'is_pinned',
        'is_muted',
        'muted_until',
        'is_favorite',
    ];

    protected function casts(): array
    {
        return [
            'is_group' => 'boolean',
            'is_archived' => 'boolean',
            'is_pinned' => 'boolean',
            'is_muted' => 'boolean',
            'is_favorite' => 'boolean',
            'last_message_at' => 'datetime',
            'muted_until' => 'datetime',
        ];
    }

    public function whatsappSession(): BelongsTo
    {
        return $this->belongsTo(WhatsappSession::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function community(): BelongsTo
    {
        return $this->belongsTo(Community::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /**
     * Последнее сообщение чата — нужно для превью в списке (иконка + локализованная
     * подпись «Фото/Видео/Голосовое (0:12)»). Сортируем так же, как в
     * ChatService::refreshChatLastMessageSnapshot, чтобы денормализованные
     * `last_message_*` колонки и это отношение ссылались на одно и то же сообщение.
     */
    public function latestMessage(): HasOne
    {
        return $this->hasOne(Message::class)->latestOfMany(['message_timestamp', 'id']);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(ChatAssignment::class);
    }

    public function assignedUsers(): HasMany
    {
        return $this->hasMany(ChatAssignment::class);
    }

    public function departments(): BelongsToMany
    {
        return $this->belongsToMany(Department::class, 'chat_department')
            ->withTimestamps();
    }
}
