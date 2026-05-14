<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Department;
use App\Services\TeamDepartmentChatSyncService;

final class DepartmentObserver
{
    public function __construct(
        private readonly TeamDepartmentChatSyncService $teamDepartmentChatSync,
    ) {}

    public function created(Department $department): void
    {
        $this->teamDepartmentChatSync->ensureDepartmentConversation($department);
    }
}
