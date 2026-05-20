<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Models\Department;
use App\Support\DepartmentWorkSchedule;
use Carbon\Carbon;
use Tests\TestCase;

final class DepartmentWorkScheduleTest extends TestCase
{
    public function test_contains_working_hours_on_enabled_day(): void
    {
        $schedule = new DepartmentWorkSchedule('Asia/Almaty', DepartmentWorkSchedule::defaultWeek());
        $moment = Carbon::parse('2026-05-19 10:30:00', 'Asia/Almaty'); // Monday

        $this->assertTrue($schedule->contains($moment));
    }

    public function test_rejects_weekend_when_disabled(): void
    {
        $schedule = new DepartmentWorkSchedule('Asia/Almaty', DepartmentWorkSchedule::defaultWeek());
        $moment = Carbon::parse('2026-05-17 12:00:00', 'Asia/Almaty'); // Saturday

        $this->assertFalse($schedule->contains($moment));
    }

    public function test_from_department_returns_null_when_schedule_disabled(): void
    {
        $department = new Department([
            'work_schedule_enabled' => false,
            'work_schedule_timezone' => 'Asia/Almaty',
            'work_schedule' => DepartmentWorkSchedule::defaultWeek(),
        ]);

        $this->assertNull(DepartmentWorkSchedule::fromDepartment($department));
    }
}
