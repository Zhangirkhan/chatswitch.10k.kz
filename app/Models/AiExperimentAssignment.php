<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class AiExperimentAssignment extends Model
{
    protected $fillable = [
        'experiment_id',
        'variant_id',
        'chat_id',
        'assigned_at',
    ];

    protected function casts(): array
    {
        return [
            'experiment_id' => 'integer',
            'variant_id' => 'integer',
            'chat_id' => 'integer',
            'assigned_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<AiExperiment, $this> */
    public function experiment(): BelongsTo
    {
        return $this->belongsTo(AiExperiment::class, 'experiment_id');
    }

    /** @return BelongsTo<AiExperimentVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(AiExperimentVariant::class, 'variant_id');
    }
}
