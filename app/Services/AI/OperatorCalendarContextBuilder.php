<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\SystemSetting;
use App\Models\User;
use App\Services\Calendar\CalendarEventsInRangeCollector;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Текстовый снимок календаря оператора для системного промпта AI: прошлые,
 * текущие и предстоящие записи с учётом прав видимости.
 */
final class OperatorCalendarContextBuilder
{
    private const PAST_DAYS = 21;

    private const FUTURE_DAYS = 56;

    private const MAX_LINES = 100;

    public function __construct(private readonly CalendarEventsInRangeCollector $collector) {}

    public function isModuleEnabled(): bool
    {
        return SystemSetting::getValue('module_calendar', 'on') === 'on';
    }

    /**
     * Блок для system-сообщения: либо список записей, либо пометка что модуль выключен.
     */
    public function buildContextBlock(User $operator): string
    {
        if (! $this->isModuleEnabled()) {
            return <<<'TXT'
Календарь организации: модуль отключён администратором. Не предлагай создавать записи в календаре и не ссылайся на него как на доступный инструмент.
TXT;
        }

        $now = Carbon::now();
        $rangeStart = $now->copy()->subDays(self::PAST_DAYS)->startOfDay();
        $rangeEnd = $now->copy()->addDays(self::FUTURE_DAYS)->endOfDay();

        /** @var Collection<int, array<string, mixed>> $rows */
        $rows = $this->collector->collect($operator, $rangeStart, $rangeEnd, 'all', null, null);

        $sorted = $rows->sortBy(fn (array $r) => Carbon::parse((string) $r['starts_at'])->timestamp)->values();

        if ($sorted->isEmpty()) {
            $p = self::PAST_DAYS;
            $f = self::FUTURE_DAYS;

            return <<<TXT
Календарь оператора ({$operator->name}): в окне с {$p} дней назад по +{$f} дней вперёд нет записей, которые этот пользователь видит по правилам доступа.
Учитывай это при предложениях: конфликтов с существующими событиями в этом окне нет.
TXT;
        }

        $past = [];
        $ongoing = [];
        $future = [];

        foreach ($sorted as $row) {
            $start = Carbon::parse((string) $row['starts_at']);
            $end = Carbon::parse((string) $row['ends_at']);
            $line = $this->formatEventLine($row, $start, $end);

            if ($end->lt($now)) {
                $past[] = $line;
            } elseif ($start->lte($now) && $end->gte($now)) {
                $ongoing[] = $line;
            } else {
                $future[] = $line;
            }
        }

        $lines = [
            'Календарь оператора (видимость как в веб-календаре ChatSwitch): окно '
                .$rangeStart->toDateString().' — '.$rangeEnd->toDateString()
                .', часовой пояс приложения: '.config('app.timezone', 'UTC').'.',
            'Ниже — уже существующие записи. Перед предложением новой записи сверяйся с ними (пересечения по времени, двойные встречи, перегруз).',
            '',
        ];

        if ($past !== []) {
            $lines[] = 'Прошедшие (для контекста, что уже было запланировано):';
            $lines = array_merge($lines, $this->capLines($past));
            $lines[] = '';
        }

        if ($ongoing !== []) {
            $lines[] = 'Сейчас идут (пересекаются с текущим моментом):';
            $lines = array_merge($lines, $ongoing);
            $lines[] = '';
        }

        if ($future !== []) {
            $lines[] = 'Предстоящие:';
            $lines = array_merge($lines, $this->capLines($future));
        }

        return trim(implode("\n", $lines));
    }

    /**
     * @param  list<string>  $items
     * @return list<string>
     */
    private function capLines(array $items): array
    {
        if (count($items) <= self::MAX_LINES) {
            return $items;
        }

        $extra = count($items) - self::MAX_LINES;

        return [...array_slice($items, 0, self::MAX_LINES), "... и ещё {$extra} записей (сокращено для промпта)."];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function formatEventLine(array $row, Carbon $start, Carbon $end): string
    {
        $title = trim((string) ($row['title'] ?? ''));
        $owner = is_array($row['owner'] ?? null) ? (string) ($row['owner']['name'] ?? '') : '';
        $assignee = is_array($row['assignee'] ?? null) ? (string) ($row['assignee']['name'] ?? '') : '';
        $allDay = (bool) ($row['all_day'] ?? false);
        $rec = $row['recurrence'] ?? null;
        $recInst = (bool) ($row['recurrence_instance'] ?? false);

        $time = $allDay
            ? $start->toDateString().' (весь день)'
            : $start->format('Y-m-d H:i').' — '.$end->format('H:i');

        $who = [];
        if ($owner !== '') {
            $who[] = 'автор: '.$owner;
        }
        if ($assignee !== '') {
            $who[] = 'ответственный: '.$assignee;
        }
        $whoStr = $who !== [] ? ' ('.implode(', ', $who).')' : '';

        $suffix = '';
        if (is_string($rec) && $rec !== '') {
            $suffix = $recInst ? " [повтор: {$rec}, экземпляр]" : " [повтор: {$rec}]";
        }

        return "- {$time}: «{$title}»{$whoStr}{$suffix}";
    }
}
