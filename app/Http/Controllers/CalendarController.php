<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\CalendarEvent;
use App\Models\SystemSetting;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Модуль календаря записей.
 *
 * Видимость: автор или ответственный; администратор — все; менеджер — записи коллег из своих отделов.
 * Повторяющиеся события раскрываются в `events()` на лету без дополнительных строк в БД.
 */
final class CalendarController extends Controller
{
    /** Максимум экземпляров в одном запросе (защита от взрыва рекурсии). */
    private const MAX_INSTANCES = 500;

    public function index(Request $request): Response
    {
        abort_unless($this->isEnabled(), 403, 'Модуль «Календарь» отключён администратором.');

        return Inertia::render('Calendar/Index', [
            'assignableUsers' => $this->calendarAssignableUsers($request->user())
                ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name])
                ->values()
                ->all(),
        ]);
    }

    /**
     * JSON-список событий для диапазона дат.
     * Раскрывает повторяющиеся события в отдельные экземпляры.
     */
    public function events(Request $request): JsonResponse
    {
        abort_unless($this->isEnabled(), 403, 'Модуль «Календарь» отключён администратором.');

        $validated = $request->validate([
            'start' => ['required', 'date'],
            'end' => ['required', 'date', 'after_or_equal:start'],
            'filter' => ['nullable', 'string', Rule::in(['all', 'mine', 'assigned_to_me'])],
            'author_id' => ['nullable', 'integer'],
            'assignee_id' => ['nullable', 'integer'],
        ]);

        $user = $request->user();
        $filterUserIds = $this->calendarFilterUserIds($user);

        if (isset($validated['author_id']) && ! in_array((int) $validated['author_id'], $filterUserIds, true)) {
            abort(422, 'Недопустимый фильтр по автору.');
        }
        if (isset($validated['assignee_id']) && ! in_array((int) $validated['assignee_id'], $filterUserIds, true)) {
            abort(422, 'Недопустимый фильтр по ответственному.');
        }

        $rangeStart = Carbon::parse($validated['start'])->startOfDay();
        $rangeEnd = Carbon::parse($validated['end'])->endOfDay();

        $query = $this->visibleCalendarEventsQuery($user)
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

        $filter = $validated['filter'] ?? 'all';
        if ($filter === 'mine') {
            $query->where('user_id', $user->id);
        } elseif ($filter === 'assigned_to_me') {
            $query->where('assignee_user_id', $user->id);
        }

        if (isset($validated['author_id'])) {
            $query->where('user_id', (int) $validated['author_id']);
        }
        if (isset($validated['assignee_id'])) {
            $query->where('assignee_user_id', (int) $validated['assignee_id']);
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

        return response()->json($result->values());
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($this->isEnabled(), 403, 'Модуль «Календарь» отключён администратором.');

        $data = $this->validateEvent($request);

        $event = CalendarEvent::create([
            'user_id' => $request->user()->id,
            ...$data,
        ]);

        return response()->json(['success' => true, 'event' => $this->transformEvent($event->fresh(['user', 'assignee']))]);
    }

    public function update(Request $request, CalendarEvent $event): JsonResponse
    {
        abort_unless($this->isEnabled(), 403, 'Модуль «Календарь» отключён администратором.');
        $this->authorizeViewable($request, $event);
        $this->authorizeEvent($request, $event);

        $data = $this->validateEvent($request);
        $event->update($data);

        return response()->json(['success' => true, 'event' => $this->transformEvent($event->fresh(['user', 'assignee']))]);
    }

    public function destroy(Request $request, CalendarEvent $event): JsonResponse
    {
        abort_unless($this->isEnabled(), 403, 'Модуль «Календарь» отключён администратором.');
        $this->authorizeViewable($request, $event);
        $this->authorizeEvent($request, $event);
        $event->delete();

        return response()->json(['success' => true]);
    }

    // ─── helpers ─────────────────────────────────────────────────────────────

    private function authorizeEvent(Request $request, CalendarEvent $event): void
    {
        if ((int) $event->user_id !== (int) $request->user()->id) {
            abort(403);
        }
    }

    private function authorizeViewable(Request $request, CalendarEvent $event): void
    {
        if (! $this->visibleCalendarEventsQuery($request->user())->whereKey($event->id)->exists()) {
            abort(404);
        }
    }

    /**
     * Записи, доступные пользователю для просмотра в календаре.
     *
     * @return Builder<CalendarEvent>
     */
    private function visibleCalendarEventsQuery(User $user): Builder
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

    /**
     * Пользователи, которых можно выбрать ответственным за запись.
     *
     * @return Collection<int, User>
     */
    private function calendarAssignableUsers(User $user): Collection
    {
        $q = User::query()->where('is_active', true)->orderBy('name');

        if ($user->hasRole('administrator')) {
            return $q->get(['id', 'name']);
        }

        if ($user->hasRole('manager')) {
            $deptIds = $user->departmentIds();
            if ($deptIds === []) {
                return new Collection;
            }

            return $q->whereHas('departments', static fn (Builder $d) => $d->whereIn('departments.id', $deptIds))
                ->get(['id', 'name']);
        }

        $deptIds = $user->departmentIds();
        if ($deptIds === []) {
            return User::query()->whereKey($user->id)->get(['id', 'name']);
        }

        return $q->where(function (Builder $w) use ($user, $deptIds): void {
            $w->whereKey($user->id)
                ->orWhereHas('departments', static fn (Builder $d) => $d->whereIn('departments.id', $deptIds));
        })->get(['id', 'name']);
    }

    /** @return list<int> */
    private function calendarAssignableUserIds(User $user): array
    {
        return $this->calendarAssignableUsers($user)->pluck('id')->map(fn ($v) => (int) $v)->values()->all();
    }

    /** Идентификаторы пользователей, допустимые в фильтрах (автор / ответственный). */
    /** @return list<int> */
    private function calendarFilterUserIds(User $user): array
    {
        if ($user->hasRole('administrator')) {
            return User::query()->where('is_active', true)->pluck('id')->map(fn ($v) => (int) $v)->values()->all();
        }

        return $this->calendarAssignableUserIds($user);
    }

    /** @return array<string, mixed> */
    private function validateEvent(Request $request): array
    {
        $user = $request->user();
        $allowedIds = $this->calendarAssignableUserIds($user);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'color' => ['nullable', 'string', 'max:16'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after_or_equal:starts_at'],
            'all_day' => ['boolean'],
            'recurrence' => ['nullable', Rule::in(CalendarEvent::RECURRENCES)],
            'recurrence_ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'assignee_user_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        if (isset($data['assignee_user_id']) && ! in_array((int) $data['assignee_user_id'], $allowedIds, true)) {
            abort(422, 'Нельзя назначить выбранного ответственного.');
        }

        $data['color'] = $data['color'] ?? '#25d366';
        $data['all_day'] = (bool) ($data['all_day'] ?? false);
        $data['assignee_user_id'] = $data['assignee_user_id'] ?? null;

        return $data;
    }

    /** @return array<string, mixed> */
    private function transformEvent(CalendarEvent $event): array
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

    private function isEnabled(): bool
    {
        return SystemSetting::getValue('module_calendar', 'on') === 'on';
    }

    /**
     * Раскрывает повторяющееся событие в массив экземпляров для диапазона.
     *
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
