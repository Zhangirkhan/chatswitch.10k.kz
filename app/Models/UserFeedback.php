<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\UserFeedbackSource;
use App\Enums\UserFeedbackStatus;
use App\Enums\UserFeedbackType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class UserFeedback extends Model
{
    protected $table = 'user_feedback';

    protected $fillable = [
        'company_id',
        'user_id',
        'source',
        'type',
        'message',
        'app_version',
        'device_platform',
        'device_model',
        'device_manufacturer',
        'os_version',
        'locale',
        'client_ip',
        'status',
        'admin_note',
        'resolved_by_user_id',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'source' => UserFeedbackSource::class,
            'type' => UserFeedbackType::class,
            'status' => UserFeedbackStatus::class,
            'message' => 'encrypted',
            'admin_note' => 'encrypted',
            'resolved_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_user_id');
    }
}
