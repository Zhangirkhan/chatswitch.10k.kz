<?php

declare(strict_types=1);

namespace Tests\Feature\SuperAdmin;

use App\Models\MobileAppRelease;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class MobileAppReleaseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('administrator', 'web');
    }

    private function adminHost(): string
    {
        return config('tenancy.admin_subdomain', 'app').'.'.config('tenancy.root_domain', 'accel.kz');
    }

    private function globalSuperAdmin(): User
    {
        return User::factory()->create([
            'is_super_admin' => true,
            'company_id' => null,
            'super_admin_scope' => 'global',
        ]);
    }

    public function test_global_super_admin_can_view_mobile_releases_page(): void
    {
        URL::forceRootUrl('https://'.$this->adminHost());

        $this->actingAs($this->globalSuperAdmin())
            ->get('/mobile-releases')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('SuperAdmin/MobileReleases/Index'));
    }

    public function test_global_super_admin_can_create_and_publish_release(): void
    {
        URL::forceRootUrl('https://'.$this->adminHost());

        $admin = $this->globalSuperAdmin();

        $this->actingAs($admin)
            ->post('/mobile-releases', [
                'platform' => 'android',
                'version_name' => '1.0.0',
                'version_code' => 10,
                'min_version_code' => 8,
                'download_url' => '/apk/app-release.apk',
                'release_notes' => 'Initial',
                'is_published' => false,
            ])
            ->assertRedirect();

        $release = MobileAppRelease::query()->first();
        $this->assertNotNull($release);
        $this->assertFalse($release->is_published);

        $this->actingAs($admin)
            ->post('/mobile-releases/'.$release->id.'/publish')
            ->assertRedirect();

        $release->refresh();
        $this->assertTrue($release->is_published);
        $this->assertNotNull($release->published_at);
    }
}
