<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\PlatformBanner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class PlatformBannerSuperAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('employee', 'web');
    }

    private function adminHost(): string
    {
        return config('tenancy.admin_subdomain', 'app').'.'.config('tenancy.root_domain', 'accel.kz');
    }

    private function tenantHost(Company $company): string
    {
        return $company->slug.'.'.config('tenancy.root_domain', 'accel.kz');
    }

    public function test_global_super_admin_can_manage_platform_banners(): void
    {
        $admin = User::factory()->create(['is_super_admin' => true, 'company_id' => null]);
        $host = $this->adminHost();
        URL::forceRootUrl('https://'.$host);

        $this->withoutVite()
            ->actingAs($admin)->get("https://{$host}/platform-banners")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('SuperAdmin/PlatformBanners/Index'));

        $this->withoutVite()
            ->actingAs($admin)->post("https://{$host}/platform-banners", [
                'message_ru' => 'Плановые техработы',
                'background_color' => '#2563eb',
                'text_color' => '#ffffff',
                'targets' => 'both',
                'is_published' => '1',
            ])->assertRedirect();

        $this->assertDatabaseHas('platform_banners', [
            'is_published' => true,
            'targets' => PlatformBanner::TARGET_BOTH,
        ]);

        $banner = PlatformBanner::query()->firstOrFail();
        $this->assertSame('Плановые техработы', $banner->message['ru']);

        $this->withoutVite()
            ->actingAs($admin)->put("https://{$host}/platform-banners/{$banner->id}", [
                'message_ru' => 'Обновлённый текст',
                'background_color' => '#dc2626',
                'text_color' => '#ffffff',
                'targets' => 'web',
                'is_published' => '0',
            ])->assertRedirect();

        $banner->refresh();
        $this->assertSame('Обновлённый текст', $banner->message['ru']);
        $this->assertFalse($banner->is_published);

        $this->actingAs($admin)->delete("https://{$host}/platform-banners/{$banner->id}")
            ->assertRedirect();

        $this->assertDatabaseMissing('platform_banners', ['id' => $banner->id]);
    }

    public function test_store_validates_hex_colors(): void
    {
        $admin = User::factory()->create(['is_super_admin' => true, 'company_id' => null]);
        $host = $this->adminHost();
        URL::forceRootUrl('https://'.$host);

        $this->actingAs($admin)->post("https://{$host}/platform-banners", [
            'message_ru' => 'Bad color',
            'background_color' => 'blue',
            'targets' => 'both',
        ])->assertSessionHasErrors('background_color');
    }

    public function test_sandbox_super_admin_cannot_access_platform_banners_admin(): void
    {
        $admin = User::factory()->create([
            'is_super_admin' => true,
            'company_id' => null,
            'super_admin_scope' => 'sandbox',
        ]);

        $host = $this->adminHost();
        URL::forceRootUrl('https://'.$host);

        $this->actingAs($admin)->get("https://{$host}/platform-banners")->assertForbidden();
    }

    public function test_authenticated_tenant_user_receives_platform_banners_in_inertia(): void
    {
        $company = $this->createTenantCompany(['slug' => 'inertia-banner-co']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $this->assignTenantRole($user, 'employee');

        PlatformBanner::query()->create([
            'company_id' => null,
            'message' => ['ru' => 'Platform notice', 'kk' => 'Platform notice', 'en' => 'Platform notice'],
            'background_color' => '#2563eb',
            'text_color' => '#ffffff',
            'targets' => PlatformBanner::TARGET_WEB,
            'priority' => 10,
            'is_published' => true,
        ]);

        PlatformBanner::query()->create([
            'company_id' => $company->id,
            'message' => ['ru' => 'Tenant notice', 'kk' => 'Tenant notice', 'en' => 'Tenant notice'],
            'background_color' => '#dc2626',
            'text_color' => '#ffffff',
            'targets' => PlatformBanner::TARGET_BOTH,
            'is_published' => true,
        ]);

        PlatformBanner::query()->create([
            'message' => ['ru' => 'Mobile only', 'kk' => 'Mobile only', 'en' => 'Mobile only'],
            'background_color' => '#64748b',
            'text_color' => '#ffffff',
            'targets' => PlatformBanner::TARGET_MOBILE,
            'is_published' => true,
        ]);

        $this->withoutVite()
            ->actingAs($user)->get('/settings/changelog')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('platformBanners', 2)
                ->where('platformBanners.0.message', 'Platform notice')
                ->where('platformBanners.1.message', 'Tenant notice'));
    }
}
