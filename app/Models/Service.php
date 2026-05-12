<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class Service extends Model
{
    protected $fillable = [
        'company_id',
        'name',
        'description',
        'duration_minutes',
        'price',
        'conditions',
        'is_active',
        'include_in_prompt',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'duration_minutes' => 'integer',
            'price' => 'decimal:2',
            'conditions' => 'array',
            'is_active' => 'boolean',
            'include_in_prompt' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
