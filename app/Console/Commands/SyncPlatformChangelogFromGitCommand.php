<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\PlatformChangelog\PlatformChangelogGitSyncService;
use Illuminate\Console\Command;

final class SyncPlatformChangelogFromGitCommand extends Command
{
    protected $signature = 'platform-changelog:sync-git {--dry-run : Показать, что будет создано, без записи в БД}';

    protected $description = 'Создаёт записи changelog из новых git-коммитов с переводом через OpenAI';

    public function handle(PlatformChangelogGitSyncService $sync): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $stats = $sync->sync($dryRun);

        foreach ($stats['errors'] as $error) {
            $this->error($error);
        }

        $this->info(sprintf(
            'Обработано коммитов: %d, создано записей: %d, пропущено: %d%s',
            $stats['processed'],
            $stats['created'],
            $stats['skipped'],
            $dryRun ? ' (dry-run)' : '',
        ));

        return $stats['errors'] !== [] && $stats['created'] === 0 && $stats['processed'] === 0
            ? self::FAILURE
            : self::SUCCESS;
    }
}
