<?php

declare(strict_types=1);

namespace App\Models\Pivots;

use Illuminate\Database\Eloquent\Relations\Pivot;

final class TeamConversationUser extends Pivot
{
    protected $table = 'team_conversation_user';

    public $incrementing = true;

    protected $fillable = [
        'team_conversation_id',
        'user_id',
        'can_leave',
        'last_read_at',
        'last_read_message_id',
        'last_delivered_message_id',
        'pinned_at',
    ];

    protected function casts(): array
    {
        return [
            'can_leave' => 'boolean',
            'last_read_at' => 'datetime',
            'pinned_at' => 'datetime',
        ];
    }
}
