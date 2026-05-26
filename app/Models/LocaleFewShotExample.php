<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class LocaleFewShotExample extends Model
{
    protected $fillable = [
        'company_id',
        'user_text',
        'assistant_text',
        'language_profile',
        'formality',
        'tags',
        'embedding',
        'source',
        'quality_score',
    ];

    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'language_profile' => 'array',
            'tags' => 'array',
            'embedding' => 'array',
            'quality_score' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Company, LocaleFewShotExample>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
