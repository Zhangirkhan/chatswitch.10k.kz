<?php

declare(strict_types=1);

namespace App\Services\Calendar;

use App\Models\CalendarEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Записи календаря, доступные пользователю для просмотра (те же правила, что в HTTP-контроллере календаря).
 *
 * @return Builder<CalendarEvent>
 */
final class VisibleCalendarEventsQuery
{
    public static function forUser(User $user): Builder
    {
        $q = CalendarEvent::query();

        if ($user->hasRole('administrator')) {
            return $q;
        }

        if ($user->hasRole('manager')) {
            $deptIds = $user->departmentIds();
            if ($deptIds === []) {
                return $q->whereRaw('0 = 1');
            }
            $peerIds = User::query()
                ->whereHas('departments', static fn (Builder $d) => $d->whereIn('departments.id', $deptIds))
                ->pluck('id')
                ->map(fn ($v) => (int) $v)
                ->all();

            return $q->where(function (Builder $w) use ($user, $peerIds): void {
                $w->where('user_id', $user->id)
                    ->orWhere('assignee_user_id', $user->id)
                    ->orWhereIn('user_id', $peerIds)
                    ->orWhereIn('assignee_user_id', $peerIds);
            });
        }

        return $q->where(function (Builder $w) use ($user): void {
            $w->where('user_id', $user->id)
                ->orWhere('assignee_user_id', $user->id);
        });
    }
}
