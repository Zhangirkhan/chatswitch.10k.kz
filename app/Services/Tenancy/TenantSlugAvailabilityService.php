<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

use App\Models\Company;

final class TenantSlugAvailabilityService
{
    private const SLUG_PATTERN = '/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/';

    /**
     * @return array{available: bool, reason: null|string, slug: string}
     */
    public function check(string $rawSlug): array
    {
        $slug = strtolower(trim($rawSlug));

        if ($slug === '' || ! preg_match(self::SLUG_PATTERN, $slug)) {
            return [
                'available' => false,
                'reason' => 'invalid',
                'slug' => $slug,
            ];
        }

        if (in_array($slug, config('tenancy.reserved_slugs', []), true)) {
            return [
                'available' => false,
                'reason' => 'reserved',
                'slug' => $slug,
            ];
        }

        $taken = Company::query()
            ->withoutGlobalScope('tenant')
            ->where('slug', $slug)
            ->exists();

        if ($taken) {
            return [
                'available' => false,
                'reason' => 'taken',
                'slug' => $slug,
            ];
        }

        return [
            'available' => true,
            'reason' => null,
            'slug' => $slug,
        ];
    }
}
