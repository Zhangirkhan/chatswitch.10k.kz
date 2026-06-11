<?php

declare(strict_types=1);

namespace App\Services\PlatformBanner;

use App\Models\PlatformBanner;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class PlatformBannerService
{
    private const LIMIT = 3;

    /**
     * @return list<array{id: int, message: string, background_color: string, text_color: string}>
     */
    public function activeForWeb(?int $companyId, string $locale): array
    {
        return $this->mapActive(
            $this->activeQuery($companyId, [PlatformBanner::TARGET_WEB, PlatformBanner::TARGET_BOTH]),
            $locale,
        );
    }

    /**
     * @return list<array{id: int, message: string, background_color: string, text_color: string}>
     */
    public function activeForMobile(?int $companyId, string $locale): array
    {
        return $this->mapActive(
            $this->activeQuery($companyId, [PlatformBanner::TARGET_MOBILE, PlatformBanner::TARGET_BOTH]),
            $locale,
        );
    }

    /**
     * @param  list<string>  $targets
     * @return Collection<int, PlatformBanner>
     */
    private function activeQuery(?int $companyId, array $targets): Collection
    {
        $now = now();

        return PlatformBanner::query()
            ->where('is_published', true)
            ->whereIn('targets', $targets)
            ->where(function (Builder $query) use ($companyId): void {
                $query->whereNull('company_id');
                if ($companyId !== null) {
                    $query->orWhere('company_id', $companyId);
                }
            })
            ->where(function (Builder $query) use ($now): void {
                $query->whereNull('starts_at')->orWhere('starts_at', '<=', $now);
            })
            ->where(function (Builder $query) use ($now): void {
                $query->whereNull('ends_at')->orWhere('ends_at', '>=', $now);
            })
            ->orderByDesc('priority')
            ->orderByDesc('id')
            ->limit(self::LIMIT)
            ->get();
    }

    /**
     * @return list<array{id: int, message: string, background_color: string, text_color: string}>
     */
    private function mapActive(Collection $banners, string $locale): array
    {
        $locale = strtolower(substr(trim($locale), 0, 2));

        return $banners
            ->map(function (PlatformBanner $banner) use ($locale): ?array {
                $message = PlatformBanner::pickTranslation($banner->message, $locale);
                if ($message === null || $message === '') {
                    return null;
                }

                return [
                    'id' => (int) $banner->id,
                    'message' => $message,
                    'background_color' => (string) $banner->background_color,
                    'text_color' => (string) ($banner->text_color ?: '#fffbeb'),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }
}
