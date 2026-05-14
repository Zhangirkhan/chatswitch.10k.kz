<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Pivots\DepartmentUser;
use App\Models\Pivots\TeamConversationUser;
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
        'company_id',
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

    /**
     * «Основной» отдел пользователя — денормализованное поле `users.department_id`.
     * Используется для legacy-отображения (подпись «(Менеджер · Отдел)» и т.п.).
     * Множественное членство — через relation {@see departments()} (pivot department_user).
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Все отделы, в которых состоит пользователь (м-к-м, источник правды для прав доступа).
     * Подмножество отделов соответствует pivot-таблице `department_user`.
     */
    public function departments(): BelongsToMany
    {
        return $this->belongsToMany(Department::class, 'department_user')
            ->using(DepartmentUser::class)
            ->withTimestamps();
    }

    public function teamConversations(): BelongsToMany
    {
        return $this->belongsToMany(TeamConversation::class, 'team_conversation_user', 'user_id', 'team_conversation_id')
            ->using(TeamConversationUser::class)
            ->withPivot(['can_leave', 'last_read_at', 'last_read_message_id', 'last_delivered_message_id', 'pinned_at'])
            ->withTimestamps();
    }

    /**
     * Удобный геттер: id всех отделов пользователя. Если связь уже подгружена —
     * берём из коллекции (без дополнительного запроса).
     *
     * @return array<int, int>
     */
    public function departmentIds(): array
    {
        if ($this->relationLoaded('departments')) {
            return $this->departments->pluck('id')->map(fn ($v) => (int) $v)->all();
        }

        return $this->departments()->pluck('departments.id')->map(fn ($v) => (int) $v)->all();
    }

    /**
     * Принадлежит ли пользователь отделу `$departmentId` хотя бы по одной связи.
     */
    public function inDepartment(int $departmentId): bool
    {
        return in_array($departmentId, $this->departmentIds(), true);
    }

    /**
     * Синхронизирует pivot department_user под массив id отделов и обновляет
     * денормализованный `users.department_id` (= наименьший id из множества или null).
     *
     * @param  array<int, int|string>  $ids
     */
    public function syncDepartments(array $ids): void
    {
        $clean = [];
        foreach ($ids as $id) {
            $int = (int) $id;
            if ($int > 0 && ! in_array($int, $clean, true)) {
                $clean[] = $int;
            }
        }

        $this->departments()->sync($clean);

        sort($clean);
        $primary = $clean[0] ?? null;

        if ((int) $this->department_id !== (int) $primary && ! ($primary === null && $this->department_id === null)) {
            $this->forceFill(['department_id' => $primary])->save();
        }
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

    /**
     * Инвалидирует все Personal Access Token (Sanctum), например после смены пароля.
     */
    public function revokeAllPersonalAccessTokens(): void
    {
        $this->tokens()->delete();
    }
}
