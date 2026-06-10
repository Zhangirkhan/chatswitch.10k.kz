<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\CalendarEvent;
use App\Models\Chat;
use App\Models\Message;
use App\Services\AI\Orchestrator\ClientMessageIntentDetector;
use App\Support\MessageInboundText;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Собирает дату/время записи из нескольких сообщений переписки и определяет,
 * что диалог про визит/покупку/встречу — не только по слову «запись».
 */
final class ConversationAppointmentResolver
{
    private const HISTORY_LIMIT = 10;

    public function __construct(
        private readonly ClientMessageIntentDetector $clientIntents,
    ) {}

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

    /**
     * Нужно ли запускать LLM-классификацию записи (широкий триггер).
     */
    public function shouldTriggerAppointmentAnalysis(Chat $chat, Message $trigger): bool
    {
        $body = mb_strtolower(trim(MessageInboundText::forMessage($trigger)));
        if ($body === '') {
            return false;
        }

        if ($this->isSupplementalDetailAfterBooking($chat, $trigger)) {
            return false;
        }

        if ($this->conversationHasBookingIntent($chat, $trigger)) {
            return true;
        }

        if ($this->parseDateTimeFromConversation($chat, $trigger) !== null) {
            return true;
        }

        $rows = $this->recentConversationRows($chat, $trigger);

        if ($this->textHasTimeSignals($body) && $this->conversationRowsHaveSchedulingContext($rows)) {
            return true;
        }

        if ($this->textIsSchedulingAffirmation($body) && $this->conversationRowsHaveSchedulingContext($rows)) {
            return true;
        }

        return $this->conversationHasSchedulingFlow($rows);
    }

    public function conversationHasBookingIntent(Chat $chat, Message $trigger): bool
    {
        $rows = $this->recentConversationRows($chat, $trigger);

        if ($this->conversationRowsHaveSchedulingContext($rows)) {
            return true;
        }

        return $this->conversationHasSchedulingFlow($rows);
    }

    public function triggerAddsSchedulingRequest(Message $trigger): bool
    {
        $body = mb_strtolower(trim(MessageInboundText::forMessage($trigger)));
        if ($body === '') {
            return false;
        }

        if ($this->clientIntents->isProvidingAddressOrDeliveryDetail($body)) {
            return false;
        }

        return $this->textHasTimeSignals($body)
            || $this->textHasExplicitBookingSignals($body)
            || $this->textHasSemanticSchedulingIntent($body);
    }

    public function isSupplementalDetailAfterBooking(Chat $chat, Message $trigger): bool
    {
        $body = mb_strtolower(trim(MessageInboundText::forMessage($trigger)));
        if ($body === '' || ! $this->clientIntents->isProvidingAddressOrDeliveryDetail($body)) {
            return false;
        }

        if ($this->triggerAddsSchedulingRequest($trigger)) {
            return false;
        }

        if ($this->findMatchingChatBooking($chat, $trigger) instanceof CalendarEvent) {
            return true;
        }

        return $this->conversationHasBookingIntent($chat, $trigger)
            && $this->hasRecentBookingConfirmation($chat, $trigger);
    }

    public function findMatchingChatBooking(Chat $chat, Message $trigger): ?CalendarEvent
    {
        $requestedAt = $this->parseDateTimeFromConversation($chat, $trigger);
        if ($requestedAt === null) {
            return null;
        }

        $events = CalendarEvent::query()
            ->where('chat_id', $chat->id)
            ->where('starts_at', '>=', now()->subDay())
            ->orderByDesc('starts_at')
            ->get();

        foreach ($events as $event) {
            if ($event->starts_at->isSameDay($requestedAt)
                && $event->starts_at->format('H:i') === $requestedAt->format('H:i')) {
                return $event;
            }
        }

        return null;
    }

    public function supplementalDeliveryReply(Chat $chat, Message $trigger): string
    {
        $address = trim(MessageInboundText::forMessage($trigger));
        $booking = $this->findMatchingChatBooking($chat, $trigger);
        $when = $booking?->starts_at?->timezone((string) config('app.timezone', 'UTC'))->format('d.m в H:i')
            ?? $this->parseDateTimeFromConversation($chat, $trigger)?->timezone((string) config('app.timezone', 'UTC'))->format('d.m в H:i');

        if ($when !== null) {
            return "Приняла адрес доставки: {$address}. Заказ остаётся на {$when} — передаю в доставку.";
        }

        return "Приняла адрес доставки: {$address}. Передаю в доставку.";
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

    /**
     * Явные слова про запись/бронь (узкий слой).
     */
    public function textHasBookingSignals(string $text): bool
    {
        return $this->textHasExplicitBookingSignals($text)
            || $this->textHasSemanticSchedulingIntent($text);
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
            'утра', 'вечера', 'днем', 'днём', 'утром', 'вечером', 'кешке', 'keshe',
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
            'жазып', 'жазылды', 'жазам', 'келес', 'келе ала', 'болады', 'бола',
            'ждём вас', 'ждем вас', 'встречаем', 'подъезжайте', 'приезжайте',
            'иә', 'иа', 'yes', 'ok',
        ] as $needle) {
            if (str_contains($reply, $needle)) {
                return true;
            }
        }

        return preg_match('/\b(\d{1,2})[:\.](\d{2})\b/u', $reply) === 1;
    }

    /**
     * @param  Collection<int, array{direction: string, text: string}>  $rows
     */
    private function conversationRowsHaveSchedulingContext(Collection $rows): bool
    {
        return $rows->contains(function (array $row): bool {
            $text = $row['text'];

            if ($this->textHasExplicitBookingSignals($text) || $this->textHasSemanticSchedulingIntent($text)) {
                return true;
            }

            if ($row['direction'] === 'outbound' && $this->textInvitesClientToSchedule($text)) {
                return true;
            }

            return false;
        });
    }

    /**
     * @param  Collection<int, array{direction: string, text: string}>  $rows
     */
    private function conversationHasSchedulingFlow(Collection $rows): bool
    {
        $indexed = $rows->values();

        foreach ($indexed as $i => $row) {
            if ($row['direction'] !== 'inbound') {
                continue;
            }

            $prevOutbound = $this->previousOutboundText($indexed, $i);
            if ($prevOutbound === null) {
                continue;
            }

            $outboundSchedules = $this->textInvitesClientToSchedule($prevOutbound)
                || $this->textHasSemanticSchedulingIntent($prevOutbound)
                || $this->textHasExplicitBookingSignals($prevOutbound);

            if (! $outboundSchedules) {
                continue;
            }

            if ($this->textHasTimeSignals($row['text'])
                || $this->textIsSchedulingAffirmation($row['text'])
                || $this->textHasSemanticSchedulingIntent($row['text'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  Collection<int, array{direction: string, text: string}>  $rows
     */
    private function previousOutboundText(Collection $rows, int $inboundIndex): ?string
    {
        for ($j = $inboundIndex - 1; $j >= 0; $j--) {
            $candidate = $rows->get($j);
            if ($candidate === null) {
                continue;
            }

            if ($candidate['direction'] === 'outbound') {
                return $candidate['text'];
            }
        }

        return null;
    }

    private function textHasExplicitBookingSignals(string $text): bool
    {
        $text = mb_strtolower(trim($text));
        if ($text === '') {
            return false;
        }

        foreach ([
            'запис', 'запиш', 'заброни', 'бронь', 'встреч', 'замер', 'выезд', 'визит',
            'монтаж', 'демонстрац', 'консультац', 'тапсырыс', 'жазыл', 'жазу', 'кездеск',
            'book', 'appointment',
        ] as $needle) {
            if (str_contains($text, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Смысловые формулировки визита/покупки/получения без слова «запись».
     */
    private function textHasSemanticSchedulingIntent(string $text): bool
    {
        $text = mb_strtolower(trim($text));
        if ($text === '') {
            return false;
        }

        foreach ([
            'приед', 'прийду', 'приду', 'подъед', 'подъех', 'заед', 'заех',
            'приехать', 'подъехать', 'заехать', 'прийти', 'прийти', 'зайти',
            'забрать', 'забер', 'заберу', 'получить', 'получу', 'заберем',
            'посмотреть', 'посмотрю', 'приехать посмотреть', 'приехать к вам',
            'к вам при', 'к вам за', 'к вам под',
            'во сколько', 'в какое время', 'какое время', 'когда можно', 'когда удоб',
            'когда смож', 'когда при', 'когда под', 'когда за',
            'можно сегодня', 'можно завтра', 'можно ли сегодня', 'можно ли завтра',
            'успею', 'получится', 'получится ли', 'смогу', 'сможем',
            'купить', 'приобрест', 'заказать', 'оформить', 'заказ',
            'заброн', 'назнач', 'договор', 'соглас',
            'келем', 'келу', 'келес', 'келуге', 'келе', 'барай', 'барам', 'барсам',
            'алу', 'алғым', 'алгым', 'алып кет', 'сатып ал',
            'бүгін', 'bugin', 'ертең', 'erten', 'кешке', 'keshe',
            'қашан', 'кашан', 'уақыт', 'уакыт', 'ыңайлы', 'ynaily', 'бола ма', 'болад',
        ] as $needle) {
            if (str_contains($text, $needle)) {
                return true;
            }
        }

        if (preg_match('/\b(?:можно|можем|бола\s*ма|болад[ыы]|болса|болсаң|болам)\b/u', $text) === 1) {
            if ($this->textHasTimeSignals($text)
                || preg_match('/\b(?:сегодня|завтра|бүгін|bugin|ертең|erten|кел|при|под|за|в)\b/u', $text) === 1) {
                return true;
            }
        }

        return preg_match('/\b(?:хочу|хотим|нужно|надо|могу|можем)\b.{0,40}\b(?:сегодня|завтра|приех|подъех|забра|купить|посмотр)/u', $text) === 1;
    }

    /**
     * Короткое согласие/подтверждение после вопроса про время.
     */
    private function textIsSchedulingAffirmation(string $text): bool
    {
        $text = mb_strtolower(trim($text));
        if ($text === '') {
            return false;
        }

        if ($this->textHasTimeSignals($text) || $this->textHasSemanticSchedulingIntent($text)) {
            return false;
        }

        return preg_match('/^(?:'
            .'да|yes|ok|okay|ок|хорошо|отлично|подойд[её]т|удобно|согласен|согласна|'
            .'договорились|давайте|можно|буду|будем|'
            .'иә|иа|жарайды|болады|болад|макул'
            .'(?:[!.…,\s]|$)'
            .')+/u', $text) === 1
            || (mb_strlen($text) <= 24 && preg_match('/^(?:да|ok|ок|иә|иа|жарайды|болады)\b/u', $text) === 1);
    }

    /**
     * Компания спрашивает или предлагает время визита.
     */
    private function textInvitesClientToSchedule(string $text): bool
    {
        $text = mb_strtolower(trim($text));
        if ($text === '') {
            return false;
        }

        foreach ([
            'когда вам удоб', 'какое время', 'во сколько', 'в какое время',
            'когда сможете', 'когда приедете', 'когда подъедете', 'когда заберете',
            'когда получите', 'когда прийдете', 'когда придете',
            'уточните время', 'подберём время', 'подберем время', 'назначим время',
            'запишем', 'записываю', 'записал на', 'ждём вас', 'ждем вас',
            'когда вам будет удоб', 'подскажите время', 'напишите время',
            'когда планируете', 'когда хотите при', 'когда хотите под',
            'қашан ыңайлы', 'кашан ynaily', 'уақытыңыз', 'уакытыныз',
            'когда можете', 'сможете сегодня', 'сможете завтра',
        ] as $needle) {
            if (str_contains($text, $needle)) {
                return true;
            }
        }

        return preg_match('/\b(?:когда|во\s+сколько|какое\s+время)\b/u', $text) === 1
            && preg_match('/\?/u', $text) === 1;
    }

    private function inferServiceName(Chat $chat, Message $trigger): string
    {
        $text = mb_strtolower($this->recentTexts($chat, $trigger)->implode("\n"));

        return match (true) {
            str_contains($text, 'замер') => 'Замер',
            str_contains($text, 'монтаж') => 'Монтаж',
            str_contains($text, 'консультац') => 'Консультация',
            str_contains($text, 'демонстрац') => 'Демонстрация',
            str_contains($text, 'забра') => 'Получение / визит',
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

        if (preg_match('/\b(?:в|на|к|to|at)\s*(\d{1,2})(?:[:\.](\d{2}))?\b/u', $text, $matches) === 1) {
            $hour = (int) $matches[1];
            $minute = isset($matches[2]) && $matches[2] !== '' ? (int) $matches[2] : 0;
            if ($hour <= 23 && $minute <= 59) {
                return ['hour' => $hour, 'minute' => $minute];
            }
        }

        if (preg_match('/\b(\d{1,2}):(\d{2})\b/u', $text, $matches) === 1) {
            $hour = (int) $matches[1];
            $minute = (int) $matches[2];
            if ($hour <= 23 && $minute <= 59) {
                return ['hour' => $hour, 'minute' => $minute];
            }
        }

        if (preg_match('/\b(\d{1,2})\.(\d{2})\b/u', $text, $matches) === 1) {
            $hour = (int) $matches[1];
            $minute = (int) $matches[2];
            if ($hour <= 23 && $minute <= 59 && ! $this->looksLikeCalendarDateToken($hour, $minute)) {
                return ['hour' => $hour, 'minute' => $minute];
            }
        }

        if (preg_match('/(?:^|\s|[^\d])(\d{1,2})(?:\s*(?:де|ге|-де|-ге|та|te|ga|ke|ке|час|сағат|sagat|уақыт|uakyt))?(?:\s|$|[^\d])/u', $text, $matches) === 1) {
            $hour = (int) $matches[1];
            if ($hour >= 0 && $hour <= 23) {
                return ['hour' => $hour, 'minute' => 0];
            }
        }

        return null;
    }

    private function looksLikeCalendarDateToken(int $first, int $second): bool
    {
        return $first >= 1 && $first <= 31 && $second >= 1 && $second <= 12;
    }

    /**
     * @return Collection<int, array{direction: string, text: string}>
     */
    private function recentConversationRows(Chat $chat, Message $trigger): Collection
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
            ->map(function (Message $message) use ($trigger): array {
                $text = (int) $message->id === (int) $trigger->id && $message->direction === 'inbound'
                    ? trim(MessageInboundText::forMessage($trigger))
                    : trim((string) $message->body);

                return [
                    'direction' => (string) $message->direction,
                    'text' => mb_strtolower($text),
                ];
            })
            ->filter(fn (array $row): bool => $row['text'] !== '')
            ->values();
    }

    /**
     * @return Collection<int, string>
     */
    private function recentTexts(Chat $chat, Message $trigger): Collection
    {
        return $this->recentConversationRows($chat, $trigger)
            ->pluck('text')
            ->values();
    }

    private function hasRecentBookingConfirmation(Chat $chat, Message $trigger): bool
    {
        return $chat->messages()
            ->where('direction', 'outbound')
            ->where('id', '<', $trigger->id)
            ->whereNotNull('body')
            ->where('body', '!=', '')
            ->latest('id')
            ->limit(3)
            ->pluck('body')
            ->contains(fn (string $body): bool => $this->replyPromisesBookingWithoutCalendar($body));
    }
}
