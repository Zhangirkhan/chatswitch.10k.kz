<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class ObjectionCluster extends Model
{
    protected $fillable = [
        'company_id',
        'label',
        'frequency',
        'win_rate_after_handling',
    ];

    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'frequency' => 'integer',
            'win_rate_after_handling' => 'decimal:2',
        ];
    }

    /** @return HasMany<ObjectionResponse, $this> */
    public function responses(): HasMany
    {
        return $this->hasMany(ObjectionResponse::class);
    }
}
