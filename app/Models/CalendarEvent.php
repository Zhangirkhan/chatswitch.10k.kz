<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Запись в календаре.
 *
 * Повторяющиеся события хранятся одной строкой с правилом `recurrence`.
 * Конкретные экземпляры для диапазона раскрываются в {@see CalendarController::events()}.
 */
final class CalendarEvent extends Model
{
    use HasFactory;

    public const RECURRENCES = ['daily', 'weekly', 'monthly', 'yearly'];

    protected $fillable = [
        'user_id',
        'assignee_user_id',
        'title',
        'description',
        'color',
        'starts_at',
        'ends_at',
        'all_day',
        'recurrence',
        'recurrence_ends_at',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'all_day' => 'boolean',
            'recurrence_ends_at' => 'date',
        ];
    }

    /**
     * @return BelongsTo<User, CalendarEvent>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<User, CalendarEvent>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_user_id');
    }
}
