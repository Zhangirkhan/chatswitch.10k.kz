<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

use App\Models\Company;
use Illuminate\Support\Facades\File;

final class TenantNginxMapService
{
    public function mapFilePath(): string
    {
        return (string) config('tenancy.nginx_known_tenants_map', '/var/www/accel/shared/nginx/known-tenants.map');
    }

    /**
     * @return list<string> FQDN известных тенантов (slug.root_domain)
     */
    public function knownTenantHosts(): array
    {
        $rootDomain = (string) config('tenancy.root_domain', 'accel.kz');

        return Company::query()
            ->withoutGlobalScope('tenant')
            ->orderBy('slug')
            ->pluck('slug')
            ->map(static fn (string $slug): string => strtolower($slug).'.'.$rootDomain)
            ->values()
            ->all();
    }

    public function writeMapFile(): int
    {
        $path = $this->mapFilePath();
        $dir = dirname($path);
        if (! is_dir($dir)) {
            File::ensureDirectoryExists($dir, 0755);
        }

        $lines = array_map(
            static fn (string $host): string => $host.' 1;',
            $this->knownTenantHosts(),
        );

        $content = implode("\n", $lines).(count($lines) > 0 ? "\n" : '');
        File::put($path, $content);

        return count($lines);
    }
}
