<?php

declare(strict_types=1);

namespace App\Services\Calendar;

use App\Models\CalendarEvent;
use App\Models\Chat;
use App\Models\Message;
use App\Models\ScheduledMessage;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

final class AppointmentBookingService
{
    private const DEFAULT_COLOR = '#25d366';

    public function __construct(
        private readonly AppointmentReminderSettings $reminderSettings,
    ) {}

    /**
     * @return array{event: CalendarEvent, reminder: ScheduledMessage|null}
     */
    public function book(
        Chat $chat,
        User $responder,
        Message $triggerMessage,
        string $serviceName,
        CarbonInterface $startsAt,
        int $durationMinutes,
        ?string $clientNote = null,
        ?User $assignee = null,
    ): array {
        $durationMinutes = max(1, $durationMinutes);
        $endsAt = Carbon::instance($startsAt->toDateTime())->copy()->addMinutes($durationMinutes);
        $startsAt = Carbon::instance($startsAt->toDateTime())->copy();

        $assignee ??= $responder;

        return DB::transaction(function () use ($chat, $responder, $assignee, $triggerMessage, $serviceName, $startsAt, $endsAt, $durationMinutes, $clientNote): array {
            $existing = CalendarEvent::query()
                ->where('trigger_message_id', $triggerMessage->id)
                ->where('source', CalendarEvent::SOURCE_AI_AUTO)
                ->first();
            if ($existing !== null) {
                $reminder = ScheduledMessage::query()
                    ->where('calendar_event_id', $existing->id)
                    ->where('purpose', ScheduledMessage::PURPOSE_APPOINTMENT_REMINDER)
                    ->first();

                return ['event' => $existing, 'reminder' => $reminder];
            }

            $event = CalendarEvent::create([
                'user_id' => $responder->id,
                'assignee_user_id' => $assignee->id,
                'chat_id' => $chat->id,
                'contact_id' => $chat->contact_id,
                'trigger_message_id' => $triggerMessage->id,
                'title' => $this->eventTitle($serviceName, $chat),
                'description' => $this->eventDescription($serviceName, $chat, $clientNote),
                'color' => self::DEFAULT_COLOR,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'all_day' => false,
                'recurrence' => null,
                'recurrence_ends_at' => null,
                'source' => CalendarEvent::SOURCE_AI_AUTO,
                'metadata' => [
                    'ai' => [
                        'generated' => true,
                        'service_name' => $serviceName,
                        'duration_minutes' => $durationMinutes,
                        'trigger_message_id' => $triggerMessage->id,
                        'assignee_user_id' => $assignee->id,
                    ],
                ],
            ]);

            $reminderAt = $this->reminderSettings->enabled()
                ? $startsAt->copy()->subMinutes($this->reminderSettings->leadTimeMinutes())
                : null;
            $reminder = $reminderAt?->isFuture() === true
                ? ScheduledMessage::create([
                    'chat_id' => $chat->id,
                    'whatsapp_session_id' => $chat->whatsapp_session_id,
                    'user_id' => $responder->id,
                    'calendar_event_id' => $event->id,
                    'purpose' => ScheduledMessage::PURPOSE_APPOINTMENT_REMINDER,
                    'body' => $this->reminderBody($serviceName, $startsAt),
                    'display_body' => $this->reminderBody($serviceName, $startsAt),
                    'scheduled_at' => $reminderAt,
                    'status' => ScheduledMessage::STATUS_PENDING,
                ])
                : null;

            return ['event' => $event, 'reminder' => $reminder];
        });
    }

    private function eventTitle(string $serviceName, Chat $chat): string
    {
        $client = trim((string) ($chat->chat_name ?: $chat->contact?->name));

        return $client !== '' ? "{$serviceName} — {$client}" : $serviceName;
    }

    private function eventDescription(string $serviceName, Chat $chat, ?string $clientNote): string
    {
        $parts = [
            'Запись создана AI из WhatsApp-чата.',
            "Услуга: {$serviceName}.",
        ];

        if ($clientNote !== null && trim($clientNote) !== '') {
            $parts[] = 'Комментарий клиента: '.trim($clientNote);
        }

        if ($chat->contact?->phone_number) {
            $parts[] = 'Телефон: '.$chat->contact->phone_number.'.';
        }

        return implode("\n", $parts);
    }

    private function reminderBody(string $serviceName, CarbonInterface $startsAt): string
    {
        $timezone = (string) config('app.timezone', 'UTC');
        $localStart = Carbon::instance($startsAt->toDateTime())->copy()->timezone($timezone);

        return "Напоминаем: вы записаны на {$serviceName} ".$localStart->format('d.m в H:i').'.';
    }
}
