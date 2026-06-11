<?php

declare(strict_types=1);

namespace App\Services\PlatformBanner;

use App\Events\PlatformBannersChanged;
use App\Models\Company;
use App\Models\PlatformBanner;
use App\Support\SafeBroadcast;

final class PlatformBannerNotifier
{
    public function notifyForBanner(PlatformBanner $banner): void
    {
        $this->notifyScope($banner->company_id !== null ? (int) $banner->company_id : null);
    }

    public function notifyScope(?int $companyId): void
    {
        if ($companyId !== null) {
            SafeBroadcast::dispatch(new PlatformBannersChanged($companyId));

            return;
        }

        SafeBroadcast::dispatch(new PlatformBannersChanged(null));

        Company::query()
            ->where('is_active', true)
            ->select('id')
            ->orderBy('id')
            ->chunkById(100, function ($companies): void {
                foreach ($companies as $company) {
                    SafeBroadcast::dispatch(new PlatformBannersChanged((int) $company->id));
                }
            });
    }
}
