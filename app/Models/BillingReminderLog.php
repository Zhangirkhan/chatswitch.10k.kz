<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class BillingReminderLog extends Model
{
    public const KIND_TRIAL_ENDING = 'trial_ending';

    public const KIND_PERIOD_RENEWAL = 'period_renewal';

    protected $fillable = [
        'company_id',
        'kind',
        'due_on',
        'days_before',
        'recipient',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'due_on' => 'date',
            'days_before' => 'integer',
            'sent_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
