<?php

declare(strict_types=1);

namespace App\Services\Department;

use App\Models\Chat;
use App\Models\Department;
use App\Support\DepartmentWorkSchedule;
use Carbon\CarbonInterface;

final class DepartmentWorkScheduleService
{
    public function isDepartmentOpen(Department $department, ?CarbonInterface $at = null): bool
    {
        $schedule = DepartmentWorkSchedule::fromDepartment($department);
        if ($schedule === null) {
            return true;
        }

        return $schedule->contains($at ?? now());
    }

    public function primaryDepartmentForChat(Chat $chat): ?Department
    {
        $chat->loadMissing('departments');

        return $chat->departments
            ->where('is_active', true)
            ->sortBy('id')
            ->first();
    }

    public function isChatWithinWorkingHours(Chat $chat, ?CarbonInterface $at = null): bool
    {
        $department = $this->primaryDepartmentForChat($chat);
        if ($department === null) {
            return true;
        }

        return $this->isDepartmentOpen($department, $at);
    }

    public function buildOffHoursReply(Department $department, ?CarbonInterface $at = null): string
    {
        $schedule = DepartmentWorkSchedule::fromDepartment($department);
        $at ??= now();

        $name = trim($department->name) !== '' ? $department->name : 'отдел';
        if ($schedule === null) {
            return 'Здравствуйте! По вашему сообщению обращение относится к отделу «'.$name.'». '
                .'Сейчас мы не на связи — ответим в ближайшее рабочее время.';
        }

        $summary = $schedule->weeklySummary();
        $next = $schedule->nextOpenLabel($at);
        $lines = [
            'Здравствуйте!',
            'По вашему сообщению мы определили отдел «'.$name.'».',
            'Сейчас у этого отдела нерабочее время.',
            'Режим работы: '.$summary.'.',
            'Ваше сообщение уже получено — ответим, как только отдел начнёт рабочий день.',
        ];

        if ($next !== null) {
            $lines[] = 'Ближайшее рабочее время: '.$next.'.';
        }

        return implode("\n", $lines);
    }
}
