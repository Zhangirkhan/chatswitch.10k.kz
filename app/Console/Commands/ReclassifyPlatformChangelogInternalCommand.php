<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\PlatformChangelogEntry;
use App\Support\PlatformChangelog\PlatformChangelogInternalHeuristic;
use Illuminate\Console\Command;

final class ReclassifyPlatformChangelogInternalCommand extends Command
{
    protected $signature = 'platform-changelog:reclassify-internal {--dry-run : Show matches without updating the database}';

    protected $description = 'Mark existing changelog entries as internal-only when they describe Super Admin or platform admin changes';

    public function handle(PlatformChangelogInternalHeuristic $heuristic): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $updated = 0;

        PlatformChangelogEntry::query()
            ->orderBy('id')
            ->each(function (PlatformChangelogEntry $entry) use ($heuristic, $dryRun, &$updated): void {
                $shouldBeInternal = $heuristic->shouldBeInternal($entry);
                $shouldBeVisible = ! $shouldBeInternal;

                if ((bool) $entry->is_user_visible === $shouldBeVisible) {
                    return;
                }

                $label = $entry->title['ru'] ?? ('#'.$entry->id);
                $this->line(sprintf(
                    '%s: [%d] %s',
                    $shouldBeVisible ? 'user-visible' : 'internal',
                    $entry->id,
                    $label,
                ));

                if (! $dryRun) {
                    $entry->update(['is_user_visible' => $shouldBeVisible]);
                }

                $updated++;
            });

        $this->info(sprintf(
            '%s %d entries.',
            $dryRun ? 'Would update' : 'Updated',
            $updated,
        ));

        return self::SUCCESS;
    }
}
