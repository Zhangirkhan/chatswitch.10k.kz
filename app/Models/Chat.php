<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Chat extends Model
{
    use HasFactory;

    protected $fillable = [
        'whatsapp_chat_id',
        'whatsapp_session_id',
        'contact_id',
        'chat_name',
        'is_group',
        'last_message_text',
        'last_message_at',
        'unread_count',
        'is_archived',
        'is_pinned',
    ];

    protected function casts(): array
    {
        return [
            'is_group' => 'boolean',
            'is_archived' => 'boolean',
            'is_pinned' => 'boolean',
            'last_message_at' => 'datetime',
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

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(ChatAssignment::class);
    }

    public function assignedUsers(): HasMany
    {
        return $this->hasMany(ChatAssignment::class);
    }
}
