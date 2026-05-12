<?php

declare(strict_types=1);

namespace App\Services\Calendar;

use App\Models\CalendarEvent;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;

/**
 * Собирает записи календаря в диапазоне дат с раскрытием повторений (как JSON-ответ эндпоинта списка событий календаря).
 */
final class CalendarEventsInRangeCollector
{
    public const MAX_INSTANCES = 500;

    /**
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    public function collect(
        User $user,
        Carbon $rangeStart,
        Carbon $rangeEnd,
        string $filter = 'all',
        ?int $authorId = null,
        ?int $assigneeId = null,
    ): \Illuminate\Support\Collection {
        $query = VisibleCalendarEventsQuery::forUser($user)
            ->with(['user:id,name', 'assignee:id,name'])
            ->where(function ($q) use ($rangeStart, $rangeEnd): void {
                $q->where(function ($q2) use ($rangeStart, $rangeEnd): void {
                    $q2->whereNull('recurrence')
                        ->where('starts_at', '<=', $rangeEnd)
                        ->where('ends_at', '>=', $rangeStart);
                })
                    ->orWhere(function ($q2) use ($rangeStart, $rangeEnd): void {
                        $q2->whereNotNull('recurrence')
                            ->where('starts_at', '<=', $rangeEnd)
                            ->where(function ($q3) use ($rangeStart): void {
                                $q3->whereNull('recurrence_ends_at')
                                    ->orWhere('recurrence_ends_at', '>=', $rangeStart->toDateString());
                            });
                    });
            });

        if ($filter === 'mine') {
            $query->where('user_id', $user->id);
        } elseif ($filter === 'assigned_to_me') {
            $query->where('assignee_user_id', $user->id);
        }

        if ($authorId !== null) {
            $query->where('user_id', $authorId);
        }
        if ($assigneeId !== null) {
            $query->where('assignee_user_id', $assigneeId);
        }

        /** @var Collection<int, CalendarEvent> $base */
        $base = $query->orderBy('starts_at')->get();

        $result = collect();

        foreach ($base as $event) {
            if ($event->recurrence === null) {
                $result->push($this->transformEvent($event));

                continue;
            }

            foreach ($this->expandRecurrence($event, $rangeStart, $rangeEnd) as $instance) {
                $result->push($instance);
                if ($result->count() >= self::MAX_INSTANCES) {
                    break 2;
                }
            }
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    public function transformEvent(CalendarEvent $event): array
    {
        $event->loadMissing(['user:id,name', 'assignee:id,name']);

        return [
            'id' => $event->id,
            'user_id' => $event->user_id,
            'owner' => $event->user ? ['id' => $event->user->id, 'name' => $event->user->name] : null,
            'assignee_user_id' => $event->assignee_user_id,
            'assignee' => $event->assignee ? ['id' => $event->assignee->id, 'name' => $event->assignee->name] : null,
            'title' => $event->title,
            'description' => $event->description,
            'color' => $event->color,
            'starts_at' => $event->starts_at->toIso8601String(),
            'ends_at' => $event->ends_at->toIso8601String(),
            'all_day' => $event->all_day,
            'recurrence' => $event->recurrence,
            'recurrence_ends_at' => $event->recurrence_ends_at?->toDateString(),
        ];
    }

    /**
     * @return iterable<array<string, mixed>>
     */
    private function expandRecurrence(CalendarEvent $event, Carbon $rangeStart, Carbon $rangeEnd): iterable
    {
        $duration = (int) $event->starts_at->diffInSeconds($event->ends_at);

        $seriesEnd = $event->recurrence_ends_at
            ? Carbon::parse($event->recurrence_ends_at)->endOfDay()
            : $rangeEnd->copy()->addYears(2);

        $current = $event->starts_at->copy();
        $count = 0;

        while ($current->lte($rangeEnd) && $current->lte($seriesEnd)) {
            $instanceEnd = $current->copy()->addSeconds($duration);

            if ($instanceEnd->gte($rangeStart)) {
                $instance = $this->transformEvent($event);
                $instance['starts_at'] = $current->toIso8601String();
                $instance['ends_at'] = $instanceEnd->toIso8601String();
                $instance['recurrence_instance'] = true;

                yield $instance;

                if (++$count >= self::MAX_INSTANCES) {
                    return;
                }
            }

            $current = match ($event->recurrence) {
                'daily' => $current->copy()->addDay(),
                'weekly' => $current->copy()->addWeek(),
                'monthly' => $current->copy()->addMonth(),
                'yearly' => $current->copy()->addYear(),
                default => $rangeEnd->copy()->addDay(),
            };
        }
    }
}
