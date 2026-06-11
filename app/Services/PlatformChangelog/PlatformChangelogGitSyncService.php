<?php

declare(strict_types=1);

namespace App\Services\PlatformChangelog;

use App\Models\PlatformChangelogEntry;
use App\Support\PlatformChangelog\GitCommitSnapshot;
use Illuminate\Support\Facades\Cache;

final class PlatformChangelogGitSyncService
{
    private const IGNORED_CACHE_KEY = 'platform_changelog.ignored_git_hashes';

    public function __construct(
        private readonly GitCommitReader $gitCommitReader,
        private readonly PlatformChangelogCommitProcessor $processor,
    ) {}

    /**
     * @return array{processed: int, created: int, skipped: int, errors: list<string>}
     */
    public function sync(bool $dryRun = false): array
    {
        $stats = [
            'processed' => 0,
            'created' => 0,
            'skipped' => 0,
            'errors' => [],
        ];

        if (! (bool) config('changelog.git_sync.enabled', true)) {
            $stats['errors'][] = 'Синхронизация changelog из git отключена (CHANGELOG_GIT_SYNC_ENABLED=false).';

            return $stats;
        }

        $limit = max(1, (int) config('changelog.git_sync.batch_limit', 20));
        $knownHashes = array_merge(
            PlatformChangelogEntry::query()
                ->whereNotNull('git_commit_hash')
                ->pluck('git_commit_hash')
                ->all(),
            array_keys($this->ignoredHashes()),
        );

        $bootstrapLimit = null;
        if ($knownHashes === []) {
            $bootstrap = (int) config('changelog.git_sync.bootstrap_commits', 15);
            if ($bootstrap > 0) {
                $bootstrapLimit = $bootstrap;
            }
        }

        try {
            $commits = $this->gitCommitReader->collectNewCommits($knownHashes, $limit, $bootstrapLimit);
        } catch (\Throwable $e) {
            $stats['errors'][] = $e->getMessage();

            return $stats;
        }

        foreach ($commits as $commit) {
            $stats['processed']++;

            if ($this->entryExists($commit->hash)) {
                $stats['skipped']++;

                continue;
            }

            $processed = $this->processor->process($commit);
            if ($processed === null) {
                $stats['errors'][] = "Не удалось обработать коммит {$commit->hash}: {$commit->subject}";
                continue;
            }

            if ($processed['include'] !== true) {
                $this->markIgnored($commit->hash);
                $stats['skipped']++;

                continue;
            }

            if ($dryRun) {
                $stats['created']++;

                continue;
            }

            PlatformChangelogEntry::query()->create([
                'git_commit_hash' => $commit->hash,
                'source_commit_subject' => mb_substr($commit->subject, 0, 500),
                'published_at' => $commit->committedAt->toDateString(),
                'title' => $processed['title'],
                'body' => $processed['body'],
                'is_published' => (bool) config('changelog.git_sync.auto_publish', true),
                'is_user_visible' => ($processed['audience'] ?? 'user') === 'user',
            ]);

            $stats['created']++;
        }

        return $stats;
    }

    private function entryExists(string $hash): bool
    {
        $normalized = strtolower($hash);

        if (isset($this->ignoredHashes()[$normalized])) {
            return true;
        }

        return PlatformChangelogEntry::query()
            ->where('git_commit_hash', $normalized)
            ->exists();
    }

    /**
     * @return array<string, true>
     */
    private function ignoredHashes(): array
    {
        $stored = Cache::get(self::IGNORED_CACHE_KEY, []);

        return is_array($stored) ? $stored : [];
    }

    private function markIgnored(string $hash): void
    {
        $ignored = $this->ignoredHashes();
        $ignored[strtolower($hash)] = true;
        Cache::forever(self::IGNORED_CACHE_KEY, $ignored);
    }
}
