<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Department;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Недельный график отдела: mon–sun, интервалы from/to в локальном timezone.
 *
 * @phpstan-type DaySlot array{enabled: bool, from: string, to: string}
 */
final class DepartmentWorkSchedule
{
    /** @var array<string, DaySlot> */
    private const DEFAULT_WEEK = [
        'mon' => ['enabled' => true, 'from' => '09:00', 'to' => '18:00'],
        'tue' => ['enabled' => true, 'from' => '09:00', 'to' => '18:00'],
        'wed' => ['enabled' => true, 'from' => '09:00', 'to' => '18:00'],
        'thu' => ['enabled' => true, 'from' => '09:00', 'to' => '18:00'],
        'fri' => ['enabled' => true, 'from' => '09:00', 'to' => '18:00'],
        'sat' => ['enabled' => false, 'from' => '09:00', 'to' => '18:00'],
        'sun' => ['enabled' => false, 'from' => '09:00', 'to' => '18:00'],
    ];

    private const DAY_LABELS = [
        'mon' => 'понедельник',
        'tue' => 'вторник',
        'wed' => 'среда',
        'thu' => 'четверг',
        'fri' => 'пятница',
        'sat' => 'суббота',
        'sun' => 'воскресенье',
    ];

    /** @var array<int, string> ISO day of week (1=Mon) → key */
    private const ISO_TO_KEY = [
        1 => 'mon',
        2 => 'tue',
        3 => 'wed',
        4 => 'thu',
        5 => 'fri',
        6 => 'sat',
        7 => 'sun',
    ];

    /**
     * @param  array<string, DaySlot>  $week
     */
    public function __construct(
        public readonly string $timezone,
        public readonly array $week,
    ) {}

    public static function defaultWeek(): array
    {
        return self::DEFAULT_WEEK;
    }

    public static function fromDepartment(Department $department): ?self
    {
        if (! $department->work_schedule_enabled) {
            return null;
        }

        $timezone = trim((string) ($department->work_schedule_timezone ?? ''));
        if ($timezone === '') {
            $timezone = (string) config('app.timezone', 'UTC');
        }

        $raw = $department->work_schedule;
        if (! is_array($raw) || $raw === []) {
            $raw = self::DEFAULT_WEEK;
        }

        return new self($timezone, self::normalizeWeek($raw));
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, DaySlot>
     */
    public static function normalizeWeek(array $raw): array
    {
        $week = self::DEFAULT_WEEK;

        foreach (array_keys(self::DEFAULT_WEEK) as $key) {
            $day = $raw[$key] ?? null;
            if (! is_array($day)) {
                continue;
            }

            $from = self::normalizeTime((string) ($day['from'] ?? $week[$key]['from']));
            $to = self::normalizeTime((string) ($day['to'] ?? $week[$key]['to']));

            $week[$key] = [
                'enabled' => filter_var($day['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'from' => $from,
                'to' => $to,
            ];
        }

        return $week;
    }

    public function contains(CarbonInterface $moment): bool
    {
        $local = Carbon::instance($moment->toDateTime())->timezone($this->timezone);
        $key = self::ISO_TO_KEY[(int) $local->format('N')] ?? null;
        if ($key === null) {
            return false;
        }

        $slot = $this->week[$key] ?? null;
        if ($slot === null || ! $slot['enabled']) {
            return false;
        }

        $from = $this->parseTimeOnDate($local, $slot['from']);
        $to = $this->parseTimeOnDate($local, $slot['to']);
        if ($from === null || $to === null || $to->lessThanOrEqualTo($from)) {
            return false;
        }

        return $local->greaterThanOrEqualTo($from) && $local->lessThan($to);
    }

    public function nextOpenAt(CarbonInterface $moment): ?Carbon
    {
        $local = Carbon::instance($moment->toDateTime())->timezone($this->timezone)->seconds(0);

        for ($offset = 0; $offset < 8; $offset++) {
            $day = $local->copy()->addDays($offset)->startOfDay();
            $key = self::ISO_TO_KEY[(int) $day->format('N')] ?? null;
            if ($key === null) {
                continue;
            }

            $slot = $this->week[$key] ?? null;
            if ($slot === null || ! $slot['enabled']) {
                continue;
            }

            $open = $this->parseTimeOnDate($day, $slot['from']);
            $close = $this->parseTimeOnDate($day, $slot['to']);
            if ($open === null || $close === null || $close->lessThanOrEqualTo($open)) {
                continue;
            }

            if ($offset === 0) {
                if ($local->lessThan($open)) {
                    return $open;
                }
                if ($local->greaterThanOrEqualTo($open) && $local->lessThan($close)) {
                    return null;
                }

                continue;
            }

            return $open;
        }

        return null;
    }

    public function weeklySummary(): string
    {
        $parts = [];
        foreach (self::ISO_TO_KEY as $key) {
            $slot = $this->week[$key];
            if (! $slot['enabled']) {
                continue;
            }
            $parts[] = self::DAY_LABELS[$key].' '.$slot['from'].'–'.$slot['to'];
        }

        return $parts === [] ? 'график не задан' : implode('; ', $parts);
    }

    public function nextOpenLabel(CarbonInterface $moment): ?string
    {
        $next = $this->nextOpenAt($moment);
        if ($next === null) {
            return null;
        }

        $local = $next->copy()->timezone($this->timezone);
        $key = self::ISO_TO_KEY[(int) $local->format('N')] ?? 'mon';
        $dayLabel = self::DAY_LABELS[$key] ?? $key;
        $today = Carbon::instance($moment->toDateTime())->timezone($this->timezone)->startOfDay();
        $prefix = $local->copy()->startOfDay()->equalTo($today) ? 'сегодня' : $dayLabel;

        return $prefix.' в '.$local->format('H:i');
    }

    private function parseTimeOnDate(Carbon $date, string $time): ?Carbon
    {
        $normalized = self::normalizeTime($time);
        if (! preg_match('/^(\d{2}):(\d{2})$/', $normalized, $m)) {
            return null;
        }

        return $date->copy()->setTime((int) $m[1], (int) $m[2], 0);
    }

    private static function normalizeTime(string $time): string
    {
        $time = trim($time);
        if (preg_match('/^(\d{1,2}):(\d{2})$/', $time, $m)) {
            return sprintf('%02d:%02d', (int) $m[1], (int) $m[2]);
        }

        return '09:00';
    }
}
