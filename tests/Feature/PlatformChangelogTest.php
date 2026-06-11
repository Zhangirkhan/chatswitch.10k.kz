<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\PlatformChangelogEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class PlatformChangelogTest extends TestCase
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

    public function test_global_super_admin_can_manage_platform_changelog(): void
    {
        $admin = User::factory()->create(['is_super_admin' => true, 'company_id' => null]);
        $host = $this->adminHost();
        URL::forceRootUrl('https://'.$host);

        $this->withoutVite()
            ->actingAs($admin)->get("https://{$host}/platform-changelog")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('SuperAdmin/PlatformChangelog/Index'));

        $this->withoutVite()
            ->actingAs($admin)->post("https://{$host}/platform-changelog", [
            'published_at' => '2026-06-11',
            'title_ru' => 'Тестовое обновление',
            'body_ru' => 'Описание изменения',
            'is_published' => '1',
        ])->assertRedirect();

        $this->assertDatabaseHas('platform_changelog_entries', [
            'is_published' => true,
        ]);

        $entry = PlatformChangelogEntry::query()->firstOrFail();
        $this->assertSame('Тестовое обновление', $entry->title['ru']);
    }

    public function test_tenant_user_sees_published_changelog_in_settings(): void
    {
        $company = Company::query()->create([
            'name' => 'Changelog Co',
            'slug' => 'changelog-co',
            'is_active' => true,
            'subscription_status' => 'trial',
        ]);
        $user = User::factory()->create(['company_id' => $company->id]);
        $user->assignRole('employee');

        PlatformChangelogEntry::query()->create([
            'published_at' => '2026-06-11',
            'title' => ['ru' => 'Публичная запись', 'kk' => 'Публичная запись', 'en' => 'Public entry'],
            'body' => ['ru' => 'Текст обновления', 'kk' => 'Текст', 'en' => 'Text'],
            'is_published' => true,
            'is_user_visible' => true,
        ]);

        PlatformChangelogEntry::query()->create([
            'published_at' => '2026-06-10',
            'title' => ['ru' => 'Внутренняя запись', 'kk' => 'Внутренняя запись', 'en' => 'Internal entry'],
            'body' => ['ru' => 'Только Super Admin', 'kk' => 'Скрыто', 'en' => 'Hidden'],
            'is_published' => true,
            'is_user_visible' => false,
        ]);

        PlatformChangelogEntry::query()->create([
            'published_at' => '2026-06-09',
            'title' => ['ru' => 'Черновик', 'kk' => 'Черновик', 'en' => 'Draft'],
            'body' => ['ru' => 'Скрыто', 'kk' => 'Скрыто', 'en' => 'Hidden'],
            'is_published' => false,
            'is_user_visible' => true,
        ]);

        $host = $this->tenantHost($company);
        URL::forceRootUrl('https://'.$host);

        $this->withoutVite()
            ->actingAs($user)->get("https://{$host}/settings/changelog")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Settings/Changelog')
                ->has('entries', 1)
                ->where('entries.0.title.ru', 'Публичная запись'));
    }

    public function test_sandbox_super_admin_cannot_access_platform_changelog_admin(): void
    {
        $admin = User::factory()->create([
            'is_super_admin' => true,
            'company_id' => null,
            'super_admin_scope' => 'sandbox',
        ]);

        $host = $this->adminHost();
        URL::forceRootUrl('https://'.$host);

        $this->actingAs($admin)->get("https://{$host}/platform-changelog")->assertForbidden();
    }
}
