<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class WinProbabilityModel extends Model
{
    protected $fillable = [
        'company_id',
        'version',
        'algorithm',
        'coefficients',
        'feature_schema',
        'training_samples',
        'metrics',
        'trained_at',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'version' => 'integer',
            'coefficients' => 'array',
            'feature_schema' => 'array',
            'training_samples' => 'integer',
            'metrics' => 'array',
            'trained_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }
}
