<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Models\MobileAppRelease;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class MobileUpdateCheckApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_no_update_when_client_is_current(): void
    {
        MobileAppRelease::query()->create([
            'platform' => 'android',
            'version_name' => '1.2.0',
            'version_code' => 12,
            'min_version_code' => 10,
            'download_url' => '/apk/app-release.apk',
            'release_notes' => 'Notes',
            'is_published' => true,
            'published_at' => now(),
        ]);

        $this->getJson('/api/v1/mobile/update-check?platform=android&version_code=12')
            ->assertOk()
            ->assertJsonPath('data.update_available', false)
            ->assertJsonPath('data.force_update', false)
            ->assertJsonPath('data.latest_version_code', 12);
    }

    public function test_returns_soft_update_when_client_is_outdated(): void
    {
        MobileAppRelease::query()->create([
            'platform' => 'android',
            'version_name' => '1.3.0',
            'version_code' => 15,
            'min_version_code' => 10,
            'download_url' => '/apk/app-release.apk',
            'release_notes' => 'Bug fixes',
            'is_published' => true,
            'published_at' => now(),
        ]);

        $this->getJson('/api/v1/mobile/update-check?platform=android&version_code=12')
            ->assertOk()
            ->assertJsonPath('data.update_available', true)
            ->assertJsonPath('data.force_update', false)
            ->assertJsonPath('data.latest_version_name', '1.3.0')
            ->assertJsonPath('data.release_notes', 'Bug fixes');
    }

    public function test_returns_force_update_when_below_min_version_code(): void
    {
        MobileAppRelease::query()->create([
            'platform' => 'android',
            'version_name' => '2.0.0',
            'version_code' => 20,
            'min_version_code' => 18,
            'download_url' => '/apk/app-release.apk',
            'is_published' => true,
            'published_at' => now(),
        ]);

        $this->getJson('/api/v1/mobile/update-check?platform=android&version_code=15')
            ->assertOk()
            ->assertJsonPath('data.update_available', true)
            ->assertJsonPath('data.force_update', true);
    }

    public function test_validates_platform(): void
    {
        $this->getJson('/api/v1/mobile/update-check?platform=windows&version_code=1')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['platform']);
    }
}
