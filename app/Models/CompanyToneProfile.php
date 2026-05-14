<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class CompanyToneProfile extends Model
{
    protected $fillable = [
        'company_id',
        'summary',
        'phrases',
        'metadata',
        'analyzed_at',
    ];

    protected function casts(): array
    {
        return [
            'phrases' => 'array',
            'metadata' => 'array',
            'analyzed_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
