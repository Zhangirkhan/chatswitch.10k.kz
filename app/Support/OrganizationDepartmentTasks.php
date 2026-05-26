<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\SystemSetting;

final class OrganizationDepartmentTasks
{
    public static function enabled(): bool
    {
        if (! config('accel.organization_department_tasks', false)) {
            return false;
        }

        return SystemSetting::getValue('module_tasks', 'on') === 'on';
    }
}
