<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class LocalePhraseChunk extends Model
{
    protected $fillable = [
        'company_id',
        'phrase',
        'meaning_ru',
        'usage_hint',
        'language_tags',
        'source',
        'embedding',
        'content_hash',
    ];

    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'language_tags' => 'array',
            'embedding' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Company, LocalePhraseChunk>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
