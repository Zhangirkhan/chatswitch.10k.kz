<?php

declare(strict_types=1);

namespace App\Models\Pivots;

use App\Models\Department;
use App\Services\TeamDepartmentChatSyncService;
use Illuminate\Database\Eloquent\Relations\Pivot;

final class DepartmentUser extends Pivot
{
    protected $table = 'department_user';

    protected static function booted(): void
    {
        self::created(function (DepartmentUser $pivot): void {
            $department = Department::query()->find((int) $pivot->department_id);
            if ($department !== null) {
                app(TeamDepartmentChatSyncService::class)->onDepartmentMemberAttached($department, (int) $pivot->user_id);
            }
        });

        self::deleted(function (DepartmentUser $pivot): void {
            $department = Department::query()->find((int) $pivot->department_id);
            if ($department !== null) {
                app(TeamDepartmentChatSyncService::class)->onDepartmentMemberDetached($department, (int) $pivot->user_id);
            }
        });
    }
}
