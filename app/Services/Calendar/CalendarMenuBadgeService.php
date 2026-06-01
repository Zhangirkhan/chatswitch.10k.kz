<?php

declare(strict_types=1);

namespace App\Services\Calendar;

use App\Models\SystemSetting;
use App\Models\User;
use Carbon\Carbon;

/** Счётчик записей календаря для бейджа в боковом меню (сегодня, ещё не завершились). */
final class CalendarMenuBadgeService
{
    public function __construct(
        private readonly CalendarEventsInRangeCollector $collector,
    ) {}

    public function countFor(User $user): int
    {
        if (SystemSetting::getValue('module_calendar', 'on') !== 'on') {
            return 0;
        }

        $now = Carbon::now();
        $events = $this->collector->collect(
            $user,
            $now->copy()->startOfDay(),
            $now->copy()->endOfDay(),
        );

        return $events
            ->filter(static fn (array $event): bool => Carbon::parse($event['ends_at'])->isAfter($now))
            ->count();
    }
}
