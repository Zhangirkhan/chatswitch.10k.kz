<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\CalendarEvent;
use App\Models\SystemSetting;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Модуль календаря записей.
 *
 * Все события принадлежат текущему пользователю (scope по `user_id`).
 * Повторяющиеся события раскрываются в `events()` на лету без дополнительных строк в БД.
 */
final class CalendarController extends Controller
{
    /** Максимум экземпляров в одном запросе (защита от взрыва рекурсии). */
    private const MAX_INSTANCES = 500;

    public function index(): Response
    {
        abort_unless($this->isEnabled(), 403, 'Модуль «Календарь» отключён администратором.');

        return Inertia::render('Calendar/Index');
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
        ]);

        $rangeStart = Carbon::parse($validated['start'])->startOfDay();
        $rangeEnd = Carbon::parse($validated['end'])->endOfDay();

        /** @var \Illuminate\Database\Eloquent\Collection<int, CalendarEvent> $base */
        $base = CalendarEvent::query()
            ->where('user_id', $request->user()->id)
            ->where(function ($q) use ($rangeStart, $rangeEnd): void {
                // Не-повторяющиеся: перекрываются с диапазоном
                $q->where(function ($q2) use ($rangeStart, $rangeEnd): void {
                    $q2->whereNull('recurrence')
                        ->where('starts_at', '<=', $rangeEnd)
                        ->where('ends_at', '>=', $rangeStart);
                })
                // Повторяющиеся: старт серии не позже конца диапазона,
                // конец серии (если задан) не раньше начала диапазона
                    ->orWhere(function ($q2) use ($rangeStart, $rangeEnd): void {
                        $q2->whereNotNull('recurrence')
                            ->where('starts_at', '<=', $rangeEnd)
                            ->where(function ($q3) use ($rangeStart): void {
                                $q3->whereNull('recurrence_ends_at')
                                    ->orWhere('recurrence_ends_at', '>=', $rangeStart->toDateString());
                            });
                    });
            })
            ->orderBy('starts_at')
            ->get();

        $result = collect();

        foreach ($base as $event) {
            if ($event->recurrence === null) {
                $result->push($this->transformEvent($event));

                continue;
            }

            // Раскрываем серию в конкретные экземпляры
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

        return response()->json(['success' => true, 'event' => $this->transformEvent($event)]);
    }

    public function update(Request $request, CalendarEvent $event): JsonResponse
    {
        abort_unless($this->isEnabled(), 403, 'Модуль «Календарь» отключён администратором.');
        $this->authorizeEvent($request, $event);

        $data = $this->validateEvent($request);
        $event->update($data);

        return response()->json(['success' => true, 'event' => $this->transformEvent($event->fresh())]);
    }

    public function destroy(Request $request, CalendarEvent $event): JsonResponse
    {
        abort_unless($this->isEnabled(), 403, 'Модуль «Kalendar» отключён администратором.');
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

    /** @return array<string, mixed> */
    private function validateEvent(Request $request): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'color' => ['nullable', 'string', 'max:16'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after_or_equal:starts_at'],
            'all_day' => ['boolean'],
            'recurrence' => ['nullable', Rule::in(CalendarEvent::RECURRENCES)],
            'recurrence_ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
        ]);

        $data['color'] = $data['color'] ?? '#25d366';
        $data['all_day'] = (bool) ($data['all_day'] ?? false);

        return $data;
    }

    /** @return array<string, mixed> */
    private function transformEvent(CalendarEvent $event): array
    {
        return [
            'id' => $event->id,
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
            : $rangeEnd->copy()->addYears(2); // cap без явной даты окончания

        $current = $event->starts_at->copy();
        $count = 0;

        while ($current->lte($rangeEnd) && $current->lte($seriesEnd)) {
            $instanceEnd = $current->copy()->addSeconds($duration);

            // Попадает ли экземпляр в диапазон?
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

            // Сдвигаем курсор на следующий период
            $current = match ($event->recurrence) {
                'daily' => $current->copy()->addDay(),
                'weekly' => $current->copy()->addWeek(),
                'monthly' => $current->copy()->addMonth(),
                'yearly' => $current->copy()->addYear(),
                default => $rangeEnd->copy()->addDay(), // выход
            };
        }
    }
}
