<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\Chat;
use App\Models\Message;
use App\Support\MessageInboundText;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Собирает дату/время записи из нескольких сообщений переписки (клиент сначала
 * согласовал визит, затем уточнил время отдельным сообщением).
 */
final class ConversationAppointmentResolver
{
    private const HISTORY_LIMIT = 10;

    /**
     * @param  list<array{user_id: int, user_name: string, starts_at: string, ends_at: string}>  $availableSlots
     * @return array{service_name: string, starts_at: string, duration_minutes: int, assignee_user_id: int}|null
     */
    public function resolve(Chat $chat, Message $trigger, array $availableSlots): ?array
    {
        if (! $this->conversationHasBookingIntent($chat, $trigger)) {
            return null;
        }

        $requestedAt = $this->parseDateTimeFromConversation($chat, $trigger);
        if ($requestedAt === null) {
            return null;
        }

        $slot = $this->matchSlot($requestedAt, $availableSlots);
        if ($slot === null) {
            return null;
        }

        return [
            'service_name' => $this->inferServiceName($chat, $trigger),
            'starts_at' => (string) $slot['starts_at'],
            'duration_minutes' => max(1, (int) Carbon::parse((string) $slot['ends_at'])->diffInMinutes(Carbon::parse((string) $slot['starts_at']))),
            'assignee_user_id' => (int) $slot['user_id'],
        ];
    }

    public function conversationHasBookingIntent(Chat $chat, Message $trigger): bool
    {
        return $this->recentTexts($chat, $trigger)
            ->contains(fn (string $text): bool => $this->textHasBookingSignals($text));
    }

    public function parseDateTimeFromConversation(Chat $chat, Message $trigger): ?CarbonInterface
    {
        $texts = $this->recentTexts($chat, $trigger);
        $date = $this->parseDateFromTexts($texts);
        $time = $this->parseTimeFromTexts($texts);

        if ($date === null || $time === null) {
            return null;
        }

        $timezone = (string) config('app.timezone', 'UTC');
        $startsAt = Carbon::create(
            $date->year,
            $date->month,
            $date->day,
            $time['hour'],
            $time['minute'],
            0,
            $timezone,
        );

        return $startsAt !== false && $startsAt->isFuture() ? $startsAt : null;
    }

    public function textHasBookingSignals(string $text): bool
    {
        $text = mb_strtolower(trim($text));
        if ($text === '') {
            return false;
        }

        foreach ([
            'запис', 'запиш', 'заброни', 'бронь', 'встреч', 'приед', 'приду', 'прийду',
            'услуг', 'процедур', 'сеанс', 'замер', 'выезд', 'визит', 'монтаж', 'демонстрац',
            'консультац', 'купить', 'приобрест', 'заказать', 'тапсырыс', 'жазыл', 'жазу',
            'келем', 'келу', 'келсе', 'келуге', 'келес', 'кездеск', 'келу', 'алу', 'алғым',
            'алгым', 'сатып ал', 'can i come', 'book', 'appointment',
        ] as $needle) {
            if (str_contains($text, $needle)) {
                return true;
            }
        }

        return preg_match('/\b(?:можно|можем|бола\s*ма|болад[ыы]|болса|болсаң)\b/u', $text) === 1
            && (
                str_contains($text, 'запис')
                || str_contains($text, 'жаз')
                || str_contains($text, 'кел')
                || str_contains($text, 'в ')
                || preg_match('/\b(\d{1,2})(?:[:\.](\d{2}))?\b/u', $text) === 1
            );
    }

    public function textHasTimeSignals(string $text): bool
    {
        $text = mb_strtolower(trim($text));
        if ($text === '') {
            return false;
        }

        if ($this->parseTimeFromText($text) !== null) {
            return true;
        }

        foreach ([
            'сегодня', 'завтра', 'послезавтра', 'после завтра',
            'бүгін', 'bugin', 'бugin', 'ертең', 'erten', 'кейін', 'kein',
            'утра', 'вечера', 'днем', 'днём', 'утром', 'вечером',
            'понедель', 'вторник', 'среду', 'четвер', 'четвёрг', 'пятниц', 'суббот', 'воскрес',
            'дүйсен', 'duisen', 'сейсен', 'сәрсен', 'sercen', 'бейсен', 'жұма', 'zhuma', 'сенбі', 'жексен',
        ] as $needle) {
            if (str_contains($text, $needle)) {
                return true;
            }
        }

        return false;
    }

    public function replyPromisesBookingWithoutCalendar(?string $reply): bool
    {
        $reply = mb_strtolower(trim((string) $reply));
        if ($reply === '') {
            return false;
        }

        foreach ([
            'записыва', 'запиш', 'записал', 'забронир', 'назнач', 'подтвержда',
            'жазып', 'жазылды', 'жазам', 'жaz', 'келес', 'келе ала', 'болады', 'бола',
            'иә', 'иа', 'yes', 'ok',
        ] as $needle) {
            if (str_contains($reply, $needle)) {
                return true;
            }
        }

        return preg_match('/\b(\d{1,2})[:\.](\d{2})\b/u', $reply) === 1;
    }

    private function inferServiceName(Chat $chat, Message $trigger): string
    {
        $text = mb_strtolower($this->recentTexts($chat, $trigger)->implode("\n"));

        return match (true) {
            str_contains($text, 'замер') => 'Замер',
            str_contains($text, 'монтаж') => 'Монтаж',
            str_contains($text, 'консультац') => 'Консультация',
            str_contains($text, 'демонстрац') => 'Демонстрация',
            str_contains($text, 'купить') || str_contains($text, 'алу') || str_contains($text, 'алғым') => 'Визит / покупка',
            default => 'Запись',
        };
    }

    /**
     * @param  list<array{user_id: int, user_name: string, starts_at: string, ends_at: string}>  $availableSlots
     * @return array{user_id: int, user_name: string, starts_at: string, ends_at: string}|null
     */
    private function matchSlot(CarbonInterface $requestedAt, array $availableSlots): ?array
    {
        $best = null;
        $bestDiff = PHP_INT_MAX;

        foreach ($availableSlots as $slot) {
            try {
                $startsAt = Carbon::parse((string) $slot['starts_at']);
            } catch (\Throwable) {
                continue;
            }

            if (! $startsAt->isSameDay($requestedAt)) {
                continue;
            }

            $diff = abs($startsAt->diffInMinutes($requestedAt, false));
            if ($diff < $bestDiff) {
                $bestDiff = $diff;
                $best = $slot;
            }
        }

        return $bestDiff <= 60 ? $best : null;
    }

    /**
     * @param  Collection<int, string>  $texts
     */
    private function parseDateFromTexts(Collection $texts): ?CarbonInterface
    {
        $timezone = (string) config('app.timezone', 'UTC');
        $now = Carbon::now($timezone);

        foreach ($texts->reverse() as $text) {
            $text = mb_strtolower(trim($text));
            if ($text === '') {
                continue;
            }

            if (str_contains($text, 'послезавтра') || str_contains($text, 'после завтра')) {
                return $now->copy()->addDays(2)->startOfDay();
            }

            if (str_contains($text, 'завтра') || str_contains($text, 'ертең') || str_contains($text, 'erten')) {
                return $now->copy()->addDay()->startOfDay();
            }

            if (str_contains($text, 'сегодня') || str_contains($text, 'бүгін') || str_contains($text, 'bugin') || str_contains($text, 'бugin')) {
                return $now->copy()->startOfDay();
            }
        }

        if ($texts->contains(fn (string $text): bool => $this->parseTimeFromText($text) !== null)) {
            return $now->copy()->startOfDay();
        }

        return null;
    }

    /**
     * @param  Collection<int, string>  $texts
     * @return array{hour: int, minute: int}|null
     */
    private function parseTimeFromTexts(Collection $texts): ?array
    {
        foreach ($texts->reverse() as $text) {
            $parsed = $this->parseTimeFromText($text);
            if ($parsed !== null) {
                return $parsed;
            }
        }

        return null;
    }

    /**
     * @return array{hour: int, minute: int}|null
     */
    private function parseTimeFromText(string $text): ?array
    {
        $text = mb_strtolower(trim($text));
        if ($text === '') {
            return null;
        }

        if (preg_match('/\b(\d{1,2})[:\.](\d{2})\b/u', $text, $matches) === 1) {
            $hour = (int) $matches[1];
            $minute = (int) $matches[2];
            if ($hour <= 23 && $minute <= 59) {
                return ['hour' => $hour, 'minute' => $minute];
            }
        }

        if (preg_match('/(?:^|\s|[^\d])(\d{1,2})(?:\s*(?:де|ге|-де|-ге|та|te|ga|ke|ке|час|сағат|sagat|уақыт|uakyt))?(?:\s|$|[^\d])/u', $text, $matches) === 1) {
            $hour = (int) $matches[1];
            if ($hour >= 0 && $hour <= 23) {
                return ['hour' => $hour, 'minute' => 0];
            }
        }

        if (preg_match('/\b(?:в|на|к|to|at)\s*(\d{1,2})\b/u', $text, $matches) === 1) {
            $hour = (int) $matches[1];
            if ($hour >= 0 && $hour <= 23) {
                return ['hour' => $hour, 'minute' => 0];
            }
        }

        return null;
    }

    /**
     * @return Collection<int, string>
     */
    private function recentTexts(Chat $chat, Message $trigger): Collection
    {
        return $chat->messages()
            ->whereIn('direction', ['inbound', 'outbound'])
            ->where(function ($query): void {
                $query->whereNotNull('body')->where('body', '!=', '');
            })
            ->orderByDesc('message_timestamp')
            ->orderByDesc('id')
            ->limit(self::HISTORY_LIMIT)
            ->get(['id', 'direction', 'body'])
            ->reverse()
            ->map(function (Message $message) use ($trigger): string {
                if ((int) $message->id === (int) $trigger->id && $message->direction === 'inbound') {
                    return trim(MessageInboundText::forMessage($trigger));
                }

                return trim((string) $message->body);
            })
            ->filter(fn (string $text): bool => $text !== '')
            ->values();
    }
}
