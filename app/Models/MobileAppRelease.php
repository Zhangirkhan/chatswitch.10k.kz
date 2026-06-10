<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class MobileAppRelease extends Model
{
    public const PLATFORM_ANDROID = 'android';

    public const PLATFORM_IOS = 'ios';

    /** @var list<string> */
    public const PLATFORMS = [
        self::PLATFORM_ANDROID,
        self::PLATFORM_IOS,
    ];

    protected $fillable = [
        'platform',
        'version_name',
        'version_code',
        'min_version_code',
        'download_url',
        'release_notes',
        'is_published',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'version_code' => 'integer',
            'min_version_code' => 'integer',
            'is_published' => 'boolean',
            'published_at' => 'datetime',
        ];
    }
}
