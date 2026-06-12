<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class WinProbabilityScore extends Model
{
    protected $fillable = [
        'company_id',
        'chat_id',
        'deal_outcome_id',
        'probability',
        'risk_factors',
        'recommended_action',
        'inputs_snapshot',
        'computed_at',
    ];

    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'chat_id' => 'integer',
            'deal_outcome_id' => 'integer',
            'probability' => 'integer',
            'risk_factors' => 'array',
            'inputs_snapshot' => 'array',
            'computed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Chat, $this> */
    public function chat(): BelongsTo
    {
        return $this->belongsTo(Chat::class);
    }
}
