<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PlatformChangelogEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

final class PlatformChangelogGitSyncTest extends TestCase
{
    use RefreshDatabase;

    private string $repoPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repoPath = sys_get_temp_dir().'/accel-changelog-git-'.uniqid('', true);
        mkdir($this->repoPath, 0777, true);

        config([
            'changelog.git_sync.enabled' => true,
            'changelog.git_sync.repository_path' => $this->repoPath,
            'changelog.git_sync.batch_limit' => 5,
            'changelog.git_sync.bootstrap_commits' => 5,
            'changelog.git_sync.auto_publish' => true,
            'services.openai.api_key' => 'test-key',
        ]);

        Cache::forget('platform_changelog.ignored_git_hashes');

        $this->initGitRepo();
    }

    protected function tearDown(): void
    {
        Process::run(['rm', '-rf', $this->repoPath]);

        parent::tearDown();
    }

    public function test_sync_creates_translated_entry_from_git_commit(): void
    {
        $hash = $this->gitCommit('feat: экспорт компаний в Excel для администраторов');

        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'include' => true,
                            'title' => [
                                'ru' => 'Excel-выгрузка компаний',
                                'kk' => 'Компанияларды Excel-ге экспорт',
                                'en' => 'Company Excel export',
                            ],
                            'body' => [
                                'ru' => 'Super Admin может выгрузить список компаний в Excel.',
                                'kk' => 'Super Admin компаниялар тізімін Excel-ге жүктей алады.',
                                'en' => 'Super Admin can export the company list to Excel.',
                            ],
                        ], JSON_UNESCAPED_UNICODE),
                    ],
                ]],
            ], 200),
        ]);

        $this->artisan('platform-changelog:sync-git')
            ->assertSuccessful();

        $this->assertDatabaseHas('platform_changelog_entries', [
            'git_commit_hash' => strtolower($hash),
            'is_published' => true,
            'is_user_visible' => true,
        ]);

        $entry = PlatformChangelogEntry::query()->where('git_commit_hash', strtolower($hash))->firstOrFail();
        $this->assertSame('Excel-выгрузка компаний', $entry->title['ru']);
        $this->assertSame('Company Excel export', $entry->title['en']);
        $this->assertSame('feat: экспорт компаний в Excel для администраторов', $entry->source_commit_subject);
    }

    public function test_sync_marks_non_user_facing_commit_as_ignored(): void
    {
        $hash = $this->gitCommit('test: fix unit tests for export service');

        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'include' => false,
                            'title' => ['ru' => '', 'kk' => '', 'en' => ''],
                            'body' => ['ru' => '', 'kk' => '', 'en' => ''],
                        ], JSON_UNESCAPED_UNICODE),
                    ],
                ]],
            ], 200),
        ]);

        Artisan::call('platform-changelog:sync-git');

        $this->assertDatabaseMissing('platform_changelog_entries', [
            'git_commit_hash' => strtolower($hash),
        ]);

        $ignored = Cache::get('platform_changelog.ignored_git_hashes', []);
        $this->assertArrayHasKey(strtolower($hash), $ignored);
    }

    public function test_sync_does_not_reprocess_existing_commit(): void
    {
        $hash = $this->gitCommit('feat: новый раздел FAQ');

        PlatformChangelogEntry::query()->create([
            'git_commit_hash' => strtolower($hash),
            'source_commit_subject' => 'feat: новый раздел FAQ',
            'published_at' => now()->toDateString(),
            'title' => ['ru' => 'Уже есть', 'kk' => 'Уже есть', 'en' => 'Exists'],
            'body' => ['ru' => 'Текст', 'kk' => 'Текст', 'en' => 'Text'],
            'is_published' => true,
        ]);

        Http::fake();

        Artisan::call('platform-changelog:sync-git');

        Http::assertNothingSent();
        $this->assertSame(1, PlatformChangelogEntry::query()->count());
    }

    public function test_sync_creates_internal_entry_when_openai_marks_internal_audience(): void
    {
        $hash = $this->gitCommit('Polish Super Admin platform banners admin UI');

        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'include' => true,
                            'audience' => 'internal',
                            'title' => [
                                'ru' => 'Улучшено управление баннерами',
                                'kk' => 'Баннерлер',
                                'en' => 'Banner admin UI',
                            ],
                            'body' => [
                                'ru' => 'Super Admin UI для баннеров.',
                                'kk' => 'Super Admin UI.',
                                'en' => 'Super Admin banner UI.',
                            ],
                        ], JSON_UNESCAPED_UNICODE),
                    ],
                ]],
            ], 200),
        ]);

        Artisan::call('platform-changelog:sync-git');

        $this->assertDatabaseHas('platform_changelog_entries', [
            'git_commit_hash' => strtolower($hash),
            'is_published' => true,
            'is_user_visible' => false,
        ]);
    }

    public function test_sync_creates_internal_entry_for_super_admin_only_paths_without_openai(): void
    {
        $dir = $this->repoPath.'/resources/js/Pages/SuperAdmin/PlatformBanners';
        mkdir($dir, 0777, true);
        $file = $dir.'/Index.vue';
        file_put_contents($file, '<template>admin</template>');
        $hash = $this->gitCommitFiles([$file], 'Polish Super Admin platform banners admin UI');

        Http::fake();

        Artisan::call('platform-changelog:sync-git');

        Http::assertNothingSent();

        $this->assertDatabaseHas('platform_changelog_entries', [
            'git_commit_hash' => strtolower($hash),
            'is_user_visible' => false,
        ]);
    }

    public function test_reclassify_internal_command_marks_existing_entries(): void
    {
        PlatformChangelogEntry::query()->create([
            'published_at' => '2026-06-11',
            'source_commit_subject' => 'Apply ui-table-panel styling to platform changelog list',
            'title' => ['ru' => 'Обновлён вид списка изменений в админ-панели', 'kk' => 'x', 'en' => 'x'],
            'body' => ['ru' => 'Super Admin changelog UI', 'kk' => 'x', 'en' => 'x'],
            'is_published' => true,
            'is_user_visible' => true,
        ]);

        $this->artisan('platform-changelog:reclassify-internal')
            ->assertSuccessful();

        $this->assertDatabaseHas('platform_changelog_entries', [
            'is_user_visible' => false,
        ]);
    }

    private function initGitRepo(): void
    {
        Process::path($this->repoPath)->run(['git', 'init', '-b', 'main']);
        Process::path($this->repoPath)->run(['git', 'config', 'user.email', 'test@example.com']);
        Process::path($this->repoPath)->run(['git', 'config', 'user.name', 'Test User']);
    }

    private function gitCommit(string $message): string
    {
        file_put_contents($this->repoPath.'/README.md', $message.' '.uniqid('', true));

        return $this->gitCommitFiles([$this->repoPath.'/README.md'], $message);
    }

    /**
     * @param  list<string>  $files
     */
    private function gitCommitFiles(array $files, string $message): string
    {
        foreach ($files as $file) {
            Process::path($this->repoPath)->run(['git', 'add', $file]);
        }
        Process::path($this->repoPath)->run(['git', 'commit', '-m', $message]);

        $result = Process::path($this->repoPath)->run(['git', 'rev-parse', 'HEAD']);

        return trim($result->output());
    }
}
