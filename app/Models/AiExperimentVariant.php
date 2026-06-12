<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class AiExperimentVariant extends Model
{
    protected $fillable = [
        'experiment_id',
        'key',
        'config',
        'is_control',
    ];

    protected function casts(): array
    {
        return [
            'experiment_id' => 'integer',
            'config' => 'array',
            'is_control' => 'boolean',
        ];
    }

    /** @return BelongsTo<AiExperiment, $this> */
    public function experiment(): BelongsTo
    {
        return $this->belongsTo(AiExperiment::class, 'experiment_id');
    }
}
