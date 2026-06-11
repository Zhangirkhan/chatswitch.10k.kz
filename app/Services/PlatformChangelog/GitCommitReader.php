<?php

declare(strict_types=1);

namespace App\Services\PlatformChangelog;

use App\Support\PlatformChangelog\GitCommitSnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Process;
use RuntimeException;

final class GitCommitReader
{
    /**
     * @param  list<string>  $knownHashes
     * @return list<GitCommitSnapshot>
     */
    public function collectNewCommits(array $knownHashes, int $limit, ?int $bootstrapLimit = null): array
    {
        if ($limit < 1) {
            return [];
        }

        $path = $this->repositoryPath();
        if (! is_dir($path.'/.git') && ! is_file($path.'/.git')) {
            throw new RuntimeException("Git-репозиторий не найден: {$path}");
        }

        $known = array_fill_keys(array_map('strtolower', $knownHashes), true);
        $fetchLimit = $knownHashes === [] && $bootstrapLimit !== null && $bootstrapLimit > 0
            ? $bootstrapLimit
            : max($limit * 3, $limit);

        $result = Process::timeout(30)->run([
            'git',
            '-C',
            $path,
            'log',
            '--no-merges',
            '-n',
            (string) $fetchLimit,
            '--date=short',
            '--pretty=format:%H%x1f%ad%x1f%s%x1f%b%x1e',
        ]);

        if (! $result->successful()) {
            throw new RuntimeException(trim($result->errorOutput()) ?: 'git log завершился с ошибкой.');
        }

        $commits = [];
        $raw = trim($result->output());
        if ($raw === '') {
            return [];
        }

        foreach (explode("\x1e", $raw) as $chunk) {
            $chunk = trim($chunk);
            if ($chunk === '') {
                continue;
            }

            $parts = explode("\x1f", $chunk, 4);
            if (count($parts) < 3) {
                continue;
            }

            [$hash, $date, $subject, $body] = [
                strtolower(trim($parts[0])),
                trim($parts[1]),
                trim($parts[2]),
                trim($parts[3] ?? ''),
            ];

            if ($hash === '' || isset($known[$hash])) {
                if ($hash !== '' && isset($known[$hash])) {
                    break;
                }

                continue;
            }

            if ($this->shouldSkipSubject($subject)) {
                continue;
            }

            $committedAt = CarbonImmutable::createFromFormat('Y-m-d', $date);
            if ($committedAt === false) {
                $committedAt = CarbonImmutable::today();
            }

            $commits[] = new GitCommitSnapshot($hash, $subject, $body, $committedAt);

            if (count($commits) >= $limit) {
                break;
            }
        }

        return array_reverse($commits);
    }

    private function repositoryPath(): string
    {
        $configured = config('changelog.git_sync.repository_path');

        return is_string($configured) && trim($configured) !== ''
            ? rtrim(trim($configured), '/')
            : base_path();
    }

    private function shouldSkipSubject(string $subject): bool
    {
        $normalized = mb_strtolower(trim($subject));

        if ($normalized === '') {
            return true;
        }

        foreach ([
            'merge ',
            'merge branch',
            'wip',
            'squash!',
            'fixup!',
        ] as $prefix) {
            if (str_starts_with($normalized, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
