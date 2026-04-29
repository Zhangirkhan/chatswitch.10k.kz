<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\PhoneFormatter;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

final class User extends Authenticatable
{
    use HasApiTokens, HasFactory, HasRoles, Notifiable;

    protected $fillable = [
        'name',
        'phone',
        'phones',
        'email',
        'password',
        'department_id',
        'is_active',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'phones' => 'array',
        ];
    }

    public function setPhoneAttribute(?string $value): void
    {
        $this->attributes['phone'] = PhoneFormatter::normalize($value);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function chatAssignments(): HasMany
    {
        return $this->hasMany(ChatAssignment::class);
    }

    public function assignedChats(): HasMany
    {
        return $this->hasMany(ChatAssignment::class);
    }

    public function whatsappSessions(): BelongsToMany
    {
        return $this->belongsToMany(WhatsappSession::class, 'user_whatsapp_session')
            ->withTimestamps();
    }
}
