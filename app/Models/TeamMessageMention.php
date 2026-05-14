<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class TeamMessageMention extends Model
{
    protected $fillable = [
        'team_message_id',
        'user_id',
    ];

    public function message(): BelongsTo
    {
        return $this->belongsTo(TeamMessage::class, 'team_message_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
