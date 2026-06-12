<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class SalesPlaybook extends Model
{
    protected $fillable = [
        'company_id',
        'slug',
        'name',
        'industry_tags',
        'qualification_fields',
        'stage_strategies',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'industry_tags' => 'array',
            'qualification_fields' => 'array',
            'stage_strategies' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /** @return HasMany<SalesPlaybookStep, $this> */
    public function steps(): HasMany
    {
        return $this->hasMany(SalesPlaybookStep::class)->orderBy('position');
    }
}
