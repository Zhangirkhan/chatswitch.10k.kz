<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Department;
use App\Services\TeamDepartmentChatSyncService;
use Illuminate\Console\Command;

final class BackfillTeamDepartmentChatsCommand extends Command
{
    protected $signature = 'team-chat:backfill-departments';

    protected $description = 'Create team conversations for all departments and sync members from department_user';

    public function handle(TeamDepartmentChatSyncService $sync): int
    {
        $count = 0;
        Department::query()->orderBy('id')->chunkById(100, function ($departments) use ($sync, &$count): void {
            foreach ($departments as $department) {
                $sync->syncAllMembers($department);
                $count++;
            }
        });

        $this->info("Synced {$count} departments.");

        return self::SUCCESS;
    }
}
