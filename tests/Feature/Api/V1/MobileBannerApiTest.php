<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Models\Company;
use App\Models\PlatformBanner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class MobileBannerApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('employee', 'web');
    }

    public function test_banners_require_authentication(): void
    {
        $company = $this->createTenantCompany(['slug' => 'banner-api-co']);

        $this->getJson('/api/v1/mobile/banners')->assertUnauthorized();
    }

    public function test_authenticated_user_receives_active_mobile_banners_for_tenant(): void
    {
        $company = $this->createTenantCompany(['slug' => 'banner-tenant-co']);
        $otherCompany = Company::query()->create([
            'name' => 'Other Co',
            'slug' => 'other-co',
            'is_active' => true,
            'subscription_status' => 'active',
        ]);
        $user = User::factory()->create(['company_id' => $company->id]);
        $this->assignTenantRole($user, 'employee');
        $token = $user->createToken('mobile')->plainTextToken;

        PlatformBanner::query()->create([
            'company_id' => null,
            'message' => ['ru' => 'Platform banner', 'kk' => 'Platform banner', 'en' => 'Platform banner'],
            'background_color' => '#2563eb',
            'text_color' => '#ffffff',
            'targets' => PlatformBanner::TARGET_BOTH,
            'priority' => 10,
            'is_published' => true,
        ]);

        PlatformBanner::query()->create([
            'company_id' => $company->id,
            'message' => ['ru' => 'Tenant banner', 'kk' => 'Tenant banner', 'en' => 'Tenant banner'],
            'background_color' => '#dc2626',
            'text_color' => '#ffffff',
            'targets' => PlatformBanner::TARGET_MOBILE,
            'is_published' => true,
        ]);

        PlatformBanner::query()->create([
            'company_id' => $otherCompany->id,
            'message' => ['ru' => 'Foreign tenant', 'kk' => 'Foreign tenant', 'en' => 'Foreign tenant'],
            'background_color' => '#000000',
            'text_color' => '#ffffff',
            'targets' => PlatformBanner::TARGET_MOBILE,
            'is_published' => true,
        ]);

        PlatformBanner::query()->create([
            'company_id' => null,
            'message' => ['ru' => 'Web only', 'kk' => 'Web only', 'en' => 'Web only'],
            'background_color' => '#64748b',
            'text_color' => '#ffffff',
            'targets' => PlatformBanner::TARGET_WEB,
            'is_published' => true,
        ]);

        $this->withToken($token)
            ->getJson('/api/v1/mobile/banners?locale=ru')
            ->assertOk()
            ->assertJsonCount(2, 'data.banners')
            ->assertJsonPath('data.banners.0.message', 'Platform banner')
            ->assertJsonPath('data.banners.0.background_color', '#2563eb')
            ->assertJsonPath('data.banners.1.message', 'Tenant banner');
    }

    public function test_banners_respect_locale(): void
    {
        $company = $this->createTenantCompany(['slug' => 'locale-banner-co']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $this->assignTenantRole($user, 'employee');
        $token = $user->createToken('mobile')->plainTextToken;

        PlatformBanner::query()->create([
            'message' => ['ru' => 'RU text', 'kk' => 'KK text', 'en' => 'EN text'],
            'background_color' => '#2563eb',
            'text_color' => '#ffffff',
            'targets' => PlatformBanner::TARGET_BOTH,
            'is_published' => true,
        ]);

        $this->withToken($token)
            ->getJson('/api/v1/mobile/banners?locale=en')
            ->assertOk()
            ->assertJsonPath('data.banners.0.message', 'EN text');
    }
}
