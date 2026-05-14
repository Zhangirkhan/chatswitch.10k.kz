<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Pivots\TeamConversationUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class TeamConversation extends Model
{
    public const TYPE_DIRECT = 'direct';

    public const TYPE_DEPARTMENT = 'department';

    protected $fillable = [
        'company_id',
        'type',
        'department_id',
        'user_low_id',
        'user_high_id',
        'last_message_at',
        'last_message_preview',
    ];

    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(TeamMessage::class, 'team_conversation_id');
    }

    public function participants(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'team_conversation_user', 'team_conversation_id', 'user_id')
            ->using(TeamConversationUser::class)
            ->withPivot(['can_leave', 'last_read_at', 'last_read_message_id', 'last_delivered_message_id', 'pinned_at'])
            ->withTimestamps();
    }

    public function userLow(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_low_id');
    }

    public function userHigh(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_high_id');
    }

    public function isDepartment(): bool
    {
        return $this->type === self::TYPE_DEPARTMENT;
    }

    public function isDirect(): bool
    {
        return $this->type === self::TYPE_DIRECT;
    }
}
