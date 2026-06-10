<?php

declare(strict_types=1);

namespace App\Services\Mobile;

use App\Models\MobileAppRelease;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class MobileAppReleaseService
{
    /**
     * @return array{
     *     update_available: bool,
     *     force_update: bool,
     *     latest_version_name: string|null,
     *     latest_version_code: int|null,
     *     min_version_code: int|null,
     *     download_url: string|null,
     *     release_notes: string|null
     * }
     */
    public function checkUpdate(string $platform, int $clientVersionCode): array
    {
        $latest = $this->latestPublished($platform);

        if ($latest === null || $clientVersionCode >= $latest->version_code) {
            return [
                'update_available' => false,
                'force_update' => false,
                'latest_version_name' => $latest?->version_name,
                'latest_version_code' => $latest?->version_code,
                'min_version_code' => $latest?->min_version_code,
                'download_url' => $this->absoluteDownloadUrl($latest?->download_url),
                'release_notes' => $latest?->release_notes,
            ];
        }

        return [
            'update_available' => true,
            'force_update' => $clientVersionCode < $latest->min_version_code,
            'latest_version_name' => $latest->version_name,
            'latest_version_code' => $latest->version_code,
            'min_version_code' => $latest->min_version_code,
            'download_url' => $this->absoluteDownloadUrl($latest->download_url),
            'release_notes' => $latest->release_notes,
        ];
    }

    public function latestPublished(string $platform): ?MobileAppRelease
    {
        return MobileAppRelease::query()
            ->where('platform', $platform)
            ->where('is_published', true)
            ->orderByDesc('version_code')
            ->first();
    }

    public function absoluteDownloadUrl(?string $url): ?string
    {
        if ($url === null || trim($url) === '') {
            return null;
        }

        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }

        return url($url);
    }

    /**
     * @param  array{
     *     platform: string,
     *     version_name: string,
     *     version_code: int,
     *     min_version_code?: int,
     *     download_url?: string|null,
     *     release_notes?: string|null,
     *     is_published?: bool
     * }  $data
     */
    public function create(array $data, ?UploadedFile $apkFile = null): MobileAppRelease
    {
        $downloadUrl = $apkFile !== null
            ? $this->storeApk($apkFile, $data['platform'], (int) $data['version_code'])
            : (string) ($data['download_url'] ?? '');

        if ($downloadUrl === '') {
            throw ValidationException::withMessages([
                'download_url' => 'Укажите ссылку на APK или загрузите файл.',
            ]);
        }

        return DB::transaction(function () use ($data, $downloadUrl): MobileAppRelease {
            $release = MobileAppRelease::query()->create([
                'platform' => $data['platform'],
                'version_name' => $data['version_name'],
                'version_code' => (int) $data['version_code'],
                'min_version_code' => (int) ($data['min_version_code'] ?? 0),
                'download_url' => $downloadUrl,
                'release_notes' => $data['release_notes'] ?? null,
                'is_published' => false,
            ]);

            if (($data['is_published'] ?? false) === true) {
                $this->publish($release);
            }

            return $release->fresh() ?? $release;
        });
    }

    /**
     * @param  array{
     *     version_name?: string,
     *     version_code?: int,
     *     min_version_code?: int,
     *     download_url?: string|null,
     *     release_notes?: string|null
     * }  $data
     */
    public function update(MobileAppRelease $release, array $data, ?UploadedFile $apkFile = null): MobileAppRelease
    {
        if ($apkFile !== null) {
            $data['download_url'] = $this->storeApk($apkFile, $release->platform, (int) ($data['version_code'] ?? $release->version_code));
        }

        $release->fill($data);
        $release->save();

        return $release->fresh() ?? $release;
    }

    public function publish(MobileAppRelease $release): MobileAppRelease
    {
        return DB::transaction(function () use ($release): MobileAppRelease {
            MobileAppRelease::query()
                ->where('platform', $release->platform)
                ->where('id', '!=', $release->id)
                ->update([
                    'is_published' => false,
                    'published_at' => null,
                ]);

            $release->forceFill([
                'is_published' => true,
                'published_at' => now(),
            ])->save();

            return $release->fresh() ?? $release;
        });
    }

    public function unpublish(MobileAppRelease $release): MobileAppRelease
    {
        $release->forceFill([
            'is_published' => false,
            'published_at' => null,
        ])->save();

        return $release->fresh() ?? $release;
    }

    private function storeApk(UploadedFile $file, string $platform, int $versionCode): string
    {
        $extension = strtolower($file->getClientOriginalExtension() ?: 'apk');
        if ($extension !== 'apk') {
            throw ValidationException::withMessages([
                'apk_file' => 'Допустим только файл .apk',
            ]);
        }

        $filename = sprintf(
            'accel-%s-%d-%s.apk',
            $platform,
            $versionCode,
            Str::lower(Str::random(6)),
        );

        $file->move(public_path('apk'), $filename);

        return '/apk/'.$filename;
    }
}
