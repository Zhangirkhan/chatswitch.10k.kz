<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\CalendarEvent;
use App\Models\Chat;
use App\Models\SystemSetting;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Записи календаря, привязанные к чату, для промпта AI: прошлые и будущие даты.
 */
final class ChatCalendarContextBuilder
{
    public const PAST_DAYS = 21;

    public const FUTURE_DAYS = 56;

    private const MAX_EVENTS = 12;

    public function isModuleEnabled(): bool
    {
        return SystemSetting::getValue('module_calendar', 'on') === 'on';
    }

    public function buildContextBlock(Chat $chat): string
    {
        if (! $this->isModuleEnabled()) {
            return '';
        }

        $now = Carbon::now();
        $rangeStart = $now->copy()->subDays(self::PAST_DAYS)->startOfDay();
        $rangeEnd = $now->copy()->addDays(self::FUTURE_DAYS)->endOfDay();

        /** @var Collection<int, CalendarEvent> $events */
        $events = CalendarEvent::query()
            ->with('assignee:id,name')
            ->where('chat_id', $chat->id)
            ->where('starts_at', '<=', $rangeEnd)
            ->where('ends_at', '>=', $rangeStart)
            ->orderBy('starts_at')
            ->limit(self::MAX_EVENTS)
            ->get(['id', 'title', 'starts_at', 'ends_at', 'assignee_user_id', 'source', 'all_day']);

        if ($events->isEmpty()) {
            return <<<'TXT'
Записи календаря в этом чате: в окне с 21 дня назад по +56 дней вперёд нет привязанных событий.
TXT;
        }

        $past = [];
        $ongoing = [];
        $future = [];

        foreach ($events as $event) {
            $start = $event->starts_at ?? $now;
            $end = $event->ends_at ?? $start;
            $line = $this->formatLine($event, $start, $end);

            if ($end->lt($now)) {
                $past[] = $line;
            } elseif ($start->lte($now) && $end->gte($now)) {
                $ongoing[] = $line;
            } else {
                $future[] = $line;
            }
        }

        $tz = (string) config('app.timezone', 'UTC');
        $lines = [
            "Записи календаря, привязанные к этому чату ({$rangeStart->toDateString()} — {$rangeEnd->toDateString()}, TZ {$tz}):",
            'При ответах о записи, переносе или напоминании опирайся на точные даты и время ниже.',
            'Не называй «завтра» или «сегодня», если в переписке это было давно, а фактическая дата записи уже в прошлом или в другой день.',
            '',
        ];

        if ($past !== []) {
            $lines[] = 'Прошедшие:';
            $lines = array_merge($lines, $past);
            $lines[] = '';
        }
        if ($ongoing !== []) {
            $lines[] = 'Сейчас:';
            $lines = array_merge($lines, $ongoing);
            $lines[] = '';
        }
        if ($future !== []) {
            $lines[] = 'Предстоящие:';
            $lines = array_merge($lines, $future);
        }

        return trim(implode("\n", $lines));
    }

    /**
     * @return Collection<int, CalendarEvent>
     */
    public function eventsInContextWindow(Chat $chat): Collection
    {
        $now = Carbon::now();
        $rangeStart = $now->copy()->subDays(self::PAST_DAYS)->startOfDay();
        $rangeEnd = $now->copy()->addDays(self::FUTURE_DAYS)->endOfDay();

        return CalendarEvent::query()
            ->where('chat_id', $chat->id)
            ->where('starts_at', '<=', $rangeEnd)
            ->where('ends_at', '>=', $rangeStart)
            ->orderBy('starts_at')
            ->limit(self::MAX_EVENTS)
            ->get();
    }

    private function formatLine(CalendarEvent $event, Carbon $start, Carbon $end): string
    {
        $title = trim((string) $event->title) ?: 'Запись';
        $assignee = trim((string) ($event->assignee?->name ?? ''));
        $when = $event->all_day
            ? $start->toDateString().' (весь день)'
            : $start->format('Y-m-d H:i').' — '.$end->format('H:i');
        $who = $assignee !== '' ? ", ответственный: {$assignee}" : '';
        $source = is_string($event->source) && $event->source !== '' ? " [{$event->source}]" : '';

        return "- {$when}: «{$title}»{$who}{$source}";
    }
}
