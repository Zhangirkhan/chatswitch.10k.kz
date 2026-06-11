<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class PlatformChangelogEntry extends Model
{
    protected $fillable = [
        'git_commit_hash',
        'source_commit_subject',
        'published_at',
        'title',
        'body',
        'is_published',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'published_at' => 'date',
            'title' => 'array',
            'body' => 'array',
            'is_published' => 'boolean',
        ];
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * @param  array<string, string|null>|null  $translations
     */
    public static function pickTranslation(?array $translations, string $locale, string $fallback = 'ru'): ?string
    {
        if ($translations === null || $translations === []) {
            return null;
        }

        $value = $translations[$locale] ?? $translations[$fallback] ?? null;
        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }

        foreach ($translations as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return null;
    }
}
