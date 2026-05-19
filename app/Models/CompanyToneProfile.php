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
        'use_manual_override',
        'manual_summary',
        'manual_phrases',
        'metadata',
        'analyzed_at',
    ];

    protected function casts(): array
    {
        return [
            'phrases' => 'array',
            'manual_phrases' => 'array',
            'use_manual_override' => 'boolean',
            'metadata' => 'array',
            'analyzed_at' => 'datetime',
        ];
    }

    public function effectiveSummary(): string
    {
        if ($this->use_manual_override && trim((string) $this->manual_summary) !== '') {
            return trim((string) $this->manual_summary);
        }

        return trim((string) ($this->summary ?? ''));
    }

    /** @return list<string> */
    public function effectivePhrases(): array
    {
        if ($this->use_manual_override && is_array($this->manual_phrases) && $this->manual_phrases !== []) {
            return collect($this->manual_phrases)
                ->filter(fn ($p): bool => is_string($p) && trim($p) !== '')
                ->map(fn (string $p): string => trim($p))
                ->values()
                ->all();
        }

        return collect($this->phrases ?? [])
            ->filter(fn ($p): bool => is_string($p) && trim($p) !== '')
            ->map(fn (string $p): string => trim($p))
            ->values()
            ->all();
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
