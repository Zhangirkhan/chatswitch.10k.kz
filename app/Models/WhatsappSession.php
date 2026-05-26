<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\PhoneFormatter;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class WhatsappSession extends Model
{
    use BelongsToTenant, HasFactory;

    /** Пользователь хочет, чтобы подключение жило; watchdog будет поднимать его автоматически. */
    public const DESIRED_ACTIVE = 'active';

    /** Пользователь сам нажал «Выйти»; трогать нельзя до явного повторного «Подключить». */
    public const DESIRED_LOGGED_OUT = 'logged_out';

    protected $fillable = [
        'company_id',
        'session_name',
        'phone_number',
        'display_name',
        'display_color',
        'wa_name',
        'wa_platform',
        'status',
        'desired_state',
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

    public function setPhoneNumberAttribute(?string $value): void
    {
        $this->attributes['phone_number'] = PhoneFormatter::normalize($value);
    }

    public function chats(): HasMany
    {
        return $this->hasMany(Chat::class, 'whatsapp_session_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class, 'whatsapp_session_id');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_whatsapp_session')
            ->withTimestamps();
    }
}
