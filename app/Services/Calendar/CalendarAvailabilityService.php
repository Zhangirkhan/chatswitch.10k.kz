<?php

declare(strict_types=1);

namespace App\Services\Calendar;

use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonInterface;

final class CalendarAvailabilityService
{
    public function __construct(private readonly CalendarEventsInRangeCollector $collector) {}

    public function hasConflict(User $assignee, CarbonInterface $startsAt, CarbonInterface $endsAt): bool
    {
        return $this->firstConflict($assignee, $startsAt, $endsAt) !== null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function firstConflict(User $assignee, CarbonInterface $startsAt, CarbonInterface $endsAt): ?array
    {
        $rangeStart = Carbon::instance($startsAt->toDateTime())->copy();
        $rangeEnd = Carbon::instance($endsAt->toDateTime())->copy();

        $events = $this->collector->collect(
            $assignee,
            $rangeStart->copy()->startOfDay(),
            $rangeEnd->copy()->endOfDay(),
            'all',
            null,
            $assignee->id,
        );

        foreach ($events as $event) {
            if ((bool) ($event['all_day'] ?? false)) {
                return $event;
            }

            $eventStartsAt = Carbon::parse((string) $event['starts_at']);
            $eventEndsAt = Carbon::parse((string) $event['ends_at']);

            if ($eventStartsAt->lt($rangeEnd) && $eventEndsAt->gt($rangeStart)) {
                return $event;
            }
        }

        return null;
    }
}
