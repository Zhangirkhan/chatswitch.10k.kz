<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class PlatformBanner extends Model
{
    public const TARGET_WEB = 'web';

    public const TARGET_MOBILE = 'mobile';

    public const TARGET_BOTH = 'both';

    protected $fillable = [
        'company_id',
        'message',
        'background_color',
        'text_color',
        'starts_at',
        'ends_at',
        'targets',
        'priority',
        'is_published',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'message' => 'array',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'is_published' => 'boolean',
            'priority' => 'integer',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
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
