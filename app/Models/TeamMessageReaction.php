<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class TeamMessageReaction extends Model
{
    protected $fillable = [
        'team_message_id',
        'user_id',
        'emoji',
    ];

    public function message(): BelongsTo
    {
        return $this->belongsTo(TeamMessage::class, 'team_message_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return array{id: int, message_id: int, user_id: int, emoji: string, user: array{id: int, name: string}|null} */
    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'message_id' => $this->team_message_id,
            'user_id' => $this->user_id,
            'emoji' => $this->emoji,
            'user' => $this->user ? [
                'id' => $this->user->id,
                'name' => (string) $this->user->name,
            ] : null,
        ];
    }
}
