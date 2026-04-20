<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class WhatsappSession extends Model
{
    use HasFactory;

    protected $fillable = [
        'session_name',
        'phone_number',
        'display_name',
        'status',
        'is_active',
        'connected_at',
        'disconnected_at',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'connected_at' => 'datetime',
            'disconnected_at' => 'datetime',
        ];
    }

    public function chats(): HasMany
    {
        return $this->hasMany(Chat::class, 'whatsapp_session_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class, 'whatsapp_session_id');
    }
}
