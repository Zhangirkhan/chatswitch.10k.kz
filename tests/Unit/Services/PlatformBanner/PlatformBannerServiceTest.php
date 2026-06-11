<?php

declare(strict_types=1);

namespace Tests\Unit\Services\PlatformBanner;

use App\Models\Company;
use App\Models\PlatformBanner;
use App\Services\PlatformBanner\PlatformBannerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PlatformBannerServiceTest extends TestCase
{
    use RefreshDatabase;

    private PlatformBannerService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(PlatformBannerService::class);
    }

    public function test_active_for_web_includes_platform_wide_and_tenant_banners(): void
    {
        $company = $this->createTenantCompany([
            'name' => 'Banner Co',
            'slug' => 'banner-co',
        ]);

        PlatformBanner::query()->create([
            'company_id' => null,
            'message' => ['ru' => 'Platform-wide', 'kk' => 'Platform-wide', 'en' => 'Platform-wide'],
            'background_color' => '#2563eb',
            'text_color' => '#ffffff',
            'targets' => PlatformBanner::TARGET_BOTH,
            'priority' => 10,
            'is_published' => true,
        ]);

        PlatformBanner::query()->create([
            'company_id' => $company->id,
            'message' => ['ru' => 'Tenant only', 'kk' => 'Tenant only', 'en' => 'Tenant only'],
            'background_color' => '#dc2626',
            'text_color' => '#ffffff',
            'targets' => PlatformBanner::TARGET_WEB,
            'priority' => 5,
            'is_published' => true,
        ]);

        $otherCompany = Company::query()->create([
            'name' => 'Other Banner Co',
            'slug' => 'other-banner-co',
            'is_active' => true,
            'subscription_status' => 'trial',
        ]);

        PlatformBanner::query()->create([
            'company_id' => $otherCompany->id,
            'message' => ['ru' => 'Other tenant', 'kk' => 'Other tenant', 'en' => 'Other tenant'],
            'background_color' => '#000000',
            'text_color' => '#ffffff',
            'targets' => PlatformBanner::TARGET_WEB,
            'is_published' => true,
        ]);

        $banners = $this->service->activeForWeb($company->id, 'ru');

        $this->assertCount(2, $banners);
        $this->assertSame('Platform-wide', $banners[0]['message']);
        $this->assertSame('Tenant only', $banners[1]['message']);
    }

    public function test_active_for_mobile_excludes_web_only_targets(): void
    {
        $company = $this->createTenantCompany([
            'name' => 'Mobile Banner Co',
            'slug' => 'mobile-banner-co',
        ]);

        PlatformBanner::query()->create([
            'company_id' => null,
            'message' => ['ru' => 'Web only', 'kk' => 'Web only', 'en' => 'Web only'],
            'background_color' => '#2563eb',
            'text_color' => '#ffffff',
            'targets' => PlatformBanner::TARGET_WEB,
            'is_published' => true,
        ]);

        PlatformBanner::query()->create([
            'company_id' => null,
            'message' => ['ru' => 'Mobile banner', 'kk' => 'Mobile banner', 'en' => 'Mobile banner'],
            'background_color' => '#2563eb',
            'text_color' => '#ffffff',
            'targets' => PlatformBanner::TARGET_MOBILE,
            'is_published' => true,
        ]);

        $banners = $this->service->activeForMobile($company->id, 'ru');

        $this->assertCount(1, $banners);
        $this->assertSame('Mobile banner', $banners[0]['message']);
    }

    public function test_schedule_and_unpublished_banners_are_excluded(): void
    {
        PlatformBanner::query()->create([
            'message' => ['ru' => 'Draft', 'kk' => 'Draft', 'en' => 'Draft'],
            'background_color' => '#2563eb',
            'text_color' => '#ffffff',
            'targets' => PlatformBanner::TARGET_BOTH,
            'is_published' => false,
        ]);

        PlatformBanner::query()->create([
            'message' => ['ru' => 'Future', 'kk' => 'Future', 'en' => 'Future'],
            'background_color' => '#2563eb',
            'text_color' => '#ffffff',
            'targets' => PlatformBanner::TARGET_BOTH,
            'starts_at' => now()->addDay(),
            'is_published' => true,
        ]);

        PlatformBanner::query()->create([
            'message' => ['ru' => 'Expired', 'kk' => 'Expired', 'en' => 'Expired'],
            'background_color' => '#2563eb',
            'text_color' => '#ffffff',
            'targets' => PlatformBanner::TARGET_BOTH,
            'ends_at' => now()->subMinute(),
            'is_published' => true,
        ]);

        PlatformBanner::query()->create([
            'message' => ['ru' => 'Active now', 'kk' => 'Active now', 'en' => 'Active now'],
            'background_color' => '#2563eb',
            'text_color' => '#ffffff',
            'targets' => PlatformBanner::TARGET_BOTH,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHour(),
            'is_published' => true,
        ]);

        $banners = $this->service->activeForWeb(null, 'ru');

        $this->assertCount(1, $banners);
        $this->assertSame('Active now', $banners[0]['message']);
    }

    public function test_locale_fallback_and_limit(): void
    {
        foreach (range(1, 4) as $index) {
            PlatformBanner::query()->create([
                'message' => [
                    'ru' => "RU {$index}",
                    'kk' => "KK {$index}",
                    'en' => "EN {$index}",
                ],
                'background_color' => '#2563eb',
                'text_color' => '#ffffff',
                'targets' => PlatformBanner::TARGET_BOTH,
                'priority' => $index,
                'is_published' => true,
            ]);
        }

        $banners = $this->service->activeForWeb(null, 'kk');

        $this->assertCount(3, $banners);
        $this->assertSame('KK 4', $banners[0]['message']);
        $this->assertSame('KK 3', $banners[1]['message']);
        $this->assertSame('KK 2', $banners[2]['message']);
    }
}
