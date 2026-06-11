<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Models\Company;
use App\Models\PlatformChangelogEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class MobileChangelogApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('employee', 'web');
    }

    private function tenantHost(Company $company): string
    {
        return $company->slug.'.'.config('tenancy.root_domain', 'accel.kz');
    }

    public function test_public_changelog_returns_only_published_entries(): void
    {
        $company = Company::query()->create([
            'name' => 'Changelog API Co',
            'slug' => 'changelog-api-co',
            'is_active' => true,
            'subscription_status' => 'trial',
        ]);
        URL::forceRootUrl('https://'.$this->tenantHost($company));

        PlatformChangelogEntry::query()->create([
            'published_at' => '2026-06-10',
            'title' => ['ru' => 'Скрытая запись', 'kk' => 'Жасырын', 'en' => 'Hidden'],
            'body' => ['ru' => 'Текст', 'kk' => 'Текст', 'en' => 'Text'],
            'is_published' => false,
        ]);

        PlatformChangelogEntry::query()->create([
            'published_at' => '2026-06-11',
            'git_commit_hash' => 'abc123def456',
            'source_commit_subject' => 'feat: linkify chat message text',
            'title' => ['ru' => 'Кликабельные ссылки', 'kk' => 'Сілтемелер', 'en' => 'Clickable links'],
            'body' => ['ru' => 'Телефоны и email в сообщениях', 'kk' => 'Телефондар', 'en' => 'Phones and email'],
            'is_published' => true,
            'is_user_visible' => true,
        ]);

        PlatformChangelogEntry::query()->create([
            'published_at' => '2026-06-10',
            'title' => ['ru' => 'Super Admin UI', 'kk' => 'x', 'en' => 'x'],
            'body' => ['ru' => 'Только админка', 'kk' => 'x', 'en' => 'x'],
            'is_published' => true,
            'is_user_visible' => false,
        ]);

        $response = $this->getJson('https://'.$this->tenantHost($company).'/api/v1/mobile/changelog?locale=ru');

        $response->assertOk()
            ->assertJsonCount(1, 'data.entries')
            ->assertJsonPath('data.entries.0.text', 'Кликабельные ссылки')
            ->assertJsonPath('data.entries.0.git_commit_hash', 'abc123def456')
            ->assertJsonPath('data.entries.0.git_commit_subject', 'feat: linkify chat message text')
            ->assertJsonPath('data.entries.0.source', 'git')
            ->assertJsonPath('data.entries.0.app_version', null);
    }

    public function test_changelog_respects_locale_and_limit_without_auth(): void
    {
        $company = Company::query()->create([
            'name' => 'Locale Co',
            'slug' => 'locale-co',
            'is_active' => true,
            'subscription_status' => 'trial',
        ]);
        URL::forceRootUrl('https://'.$this->tenantHost($company));

        foreach (range(1, 3) as $index) {
            PlatformChangelogEntry::query()->create([
                'published_at' => sprintf('2026-06-%02d', $index),
                'title' => ['ru' => "RU {$index}", 'kk' => "KK {$index}", 'en' => "EN {$index}"],
                'body' => ['ru' => 'body', 'kk' => 'body', 'en' => 'body'],
                'is_published' => true,
            ]);
        }

        $this->getJson('https://'.$this->tenantHost($company).'/api/v1/mobile/changelog?locale=kk&limit=2')
            ->assertOk()
            ->assertJsonCount(2, 'data.entries')
            ->assertJsonPath('data.entries.0.text', 'KK 3');
    }

    public function test_changelog_works_on_global_host(): void
    {
        $host = config('tenancy.root_domain', 'accel.kz');
        URL::forceRootUrl('https://'.$host);

        PlatformChangelogEntry::query()->create([
            'published_at' => '2026-06-11',
            'title' => ['ru' => 'Глобальная запись', 'kk' => 'Жалпы', 'en' => 'Global'],
            'body' => ['ru' => 'Текст', 'kk' => 'Текст', 'en' => 'Text'],
            'is_published' => true,
        ]);

        $this->getJson("https://{$host}/api/v1/mobile/changelog?locale=ru")
            ->assertOk()
            ->assertJsonPath('data.entries.0.text', 'Глобальная запись');
    }

    public function test_authenticated_user_can_read_public_changelog(): void
    {
        $company = Company::query()->create([
            'name' => 'Auth Co',
            'slug' => 'auth-co',
            'is_active' => true,
            'subscription_status' => 'trial',
        ]);
        $user = User::factory()->create(['company_id' => $company->id]);
        $user->assignRole('employee');
        $token = $user->createToken('mobile')->plainTextToken;
        URL::forceRootUrl('https://'.$this->tenantHost($company));

        PlatformChangelogEntry::query()->create([
            'published_at' => '2026-06-11',
            'title' => ['ru' => 'Для всех', 'kk' => 'Барлығына', 'en' => 'For all'],
            'body' => ['ru' => 'Текст', 'kk' => 'Текст', 'en' => 'Text'],
            'is_published' => true,
        ]);

        $this->withToken($token)
            ->getJson('https://'.$this->tenantHost($company).'/api/v1/mobile/changelog?locale=ru')
            ->assertOk()
            ->assertJsonPath('data.entries.0.text', 'Для всех');
    }
}
