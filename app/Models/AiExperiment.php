<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class AiExperiment extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_PAUSED = 'paused';

    public const STATUS_COMPLETED = 'completed';

    public const TARGET_AI_REPLY = 'ai_reply';

    protected $fillable = [
        'company_id',
        'slug',
        'name',
        'target',
        'status',
        'traffic_percent',
        'started_at',
        'ended_at',
    ];

    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'traffic_percent' => 'integer',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    /** @return HasMany<AiExperimentVariant, $this> */
    public function variants(): HasMany
    {
        return $this->hasMany(AiExperimentVariant::class, 'experiment_id');
    }

    /** @return HasMany<AiExperimentAssignment, $this> */
    public function assignments(): HasMany
    {
        return $this->hasMany(AiExperimentAssignment::class, 'experiment_id');
    }
}
