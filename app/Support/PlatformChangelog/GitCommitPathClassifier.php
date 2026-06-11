<?php

declare(strict_types=1);

namespace App\Support\PlatformChangelog;

final class GitCommitPathClassifier
{
    /** @var list<string> */
    private const INTERNAL_PATH_PREFIXES = [
        'app/Http/Controllers/SuperAdmin/',
        'app/Services/PlatformBanner/',
        'app/Services/PlatformChangelog/',
        'app/Services/SuperAdmin/',
        'resources/js/Pages/SuperAdmin/',
        'resources/js/Layouts/SuperAdminLayout.vue',
        'routes/admin.php',
        'tests/Feature/PlatformBannerSuperAdminTest.php',
        'tests/Feature/PlatformChangelogTest.php',
    ];

    /**
     * @param  list<string>  $changedPaths
     */
    public function isInternalOnly(array $changedPaths): bool
    {
        $paths = array_values(array_filter(array_map(
            fn (string $path): string => $this->normalizePath($path),
            $changedPaths,
        )));

        if ($paths === []) {
            return false;
        }

        foreach ($paths as $path) {
            if (! $this->matchesInternalPath($path)) {
                return false;
            }
        }

        return true;
    }

    private function normalizePath(string $path): string
    {
        return ltrim(str_replace('\\', '/', trim($path)), '/');
    }

    private function matchesInternalPath(string $path): bool
    {
        foreach (self::INTERNAL_PATH_PREFIXES as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
