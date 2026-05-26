<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Subscription extends Model
{
    protected $fillable = [
        'company_id',
        'plan_id',
        'status',
        'event',
        'started_at',
        'ends_at',
        'trial_ends_at',
        'ended_at',
        'canceled_at',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ends_at' => 'datetime',
            'trial_ends_at' => 'datetime',
            'ended_at' => 'datetime',
            'canceled_at' => 'datetime',
        ];
    }

    public function isOpen(): bool
    {
        return $this->ended_at === null
            && in_array($this->status, ['trial', 'active', 'past_due'], true);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }
}
