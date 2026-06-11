<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class UserFeedbackLike extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_feedback_id',
        'user_id',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    public function feedback(): BelongsTo
    {
        return $this->belongsTo(UserFeedback::class, 'user_feedback_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
