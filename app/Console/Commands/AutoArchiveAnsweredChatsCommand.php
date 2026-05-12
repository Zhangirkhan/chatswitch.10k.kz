<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\AutoArchiveAnsweredChatsService;
use Illuminate\Console\Command;

final class AutoArchiveAnsweredChatsCommand extends Command
{
    protected $signature = 'chats:auto-archive-answered {--dry-run : Only print how many chats would be archived}';

    protected $description = 'Archive chats whose last message is an outbound reply from a staff user (nightly cleanup).';

    public function handle(AutoArchiveAnsweredChatsService $service): int
    {
        if ($this->option('dry-run')) {
            $n = $service->countEligible();
            $this->info("Would archive {$n} chat(s).");

            return self::SUCCESS;
        }

        $n = $service->archiveEligibleChats();
        $this->info("Archived {$n} chat(s) with staff as last sender.");

        return self::SUCCESS;
    }
}
