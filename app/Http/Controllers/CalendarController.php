<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\CalendarEvent;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\Calendar\CalendarEventsInRangeCollector;
use App\Services\Calendar\VisibleCalendarEventsQuery;
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
    public function events(Request $request, CalendarEventsInRangeCollector $collector): JsonResponse
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

        $filter = $validated['filter'] ?? 'all';

        $result = $collector->collect(
            $user,
            $rangeStart,
            $rangeEnd,
            $filter,
            isset($validated['author_id']) ? (int) $validated['author_id'] : null,
            isset($validated['assignee_id']) ? (int) $validated['assignee_id'] : null,
        );

        return response()->json($result->values());
    }

    public function store(Request $request, CalendarEventsInRangeCollector $collector): JsonResponse
    {
        abort_unless($this->isEnabled(), 403, 'Модуль «Календарь» отключён администратором.');

        $data = $this->validateEvent($request);

        $event = CalendarEvent::create([
            'user_id' => $request->user()->id,
            ...$data,
        ]);

        return response()->json(['success' => true, 'event' => $collector->transformEvent($event->fresh(['user', 'assignee']))]);
    }

    public function update(Request $request, CalendarEvent $event, CalendarEventsInRangeCollector $collector): JsonResponse
    {
        abort_unless($this->isEnabled(), 403, 'Модуль «Календарь» отключён администратором.');
        $this->authorizeViewable($request, $event);
        $this->authorizeEvent($request, $event);

        $data = $this->validateEvent($request);
        $event->update($data);

        return response()->json(['success' => true, 'event' => $collector->transformEvent($event->fresh(['user', 'assignee']))]);
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
        if (! VisibleCalendarEventsQuery::forUser($request->user())->whereKey($event->id)->exists()) {
            abort(404);
        }
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

    private function isEnabled(): bool
    {
        return SystemSetting::getValue('module_calendar', 'on') === 'on';
    }
}
