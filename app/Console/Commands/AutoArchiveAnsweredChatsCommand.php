<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\AutoArchiveAnsweredChatsService;
use App\Support\TenantConsole;
use Illuminate\Console\Command;

final class AutoArchiveAnsweredChatsCommand extends Command
{
    protected $signature = 'chats:auto-archive-answered {--dry-run : Only print how many chats would be archived}';

    protected $description = 'Archive chats whose last message is an outbound reply from a staff user (nightly cleanup).';

    public function handle(AutoArchiveAnsweredChatsService $service, TenantConsole $tenantConsole): int
    {
        if ($this->option('dry-run')) {
            $total = 0;
            $tenantConsole->eachActiveCompany(function ($company) use ($service, &$total): void {
                $total += $service->countEligible((int) $company->id);
            });
            $this->info("Would archive {$total} chat(s).");

            return self::SUCCESS;
        }

        $archived = 0;
        $tenantConsole->eachActiveCompany(function ($company) use ($service, &$archived): void {
            $archived += $service->archiveEligibleChats((int) $company->id);
        });

        $this->info("Archived {$archived} chat(s) with staff as last sender.");

        return self::SUCCESS;
    }
}
