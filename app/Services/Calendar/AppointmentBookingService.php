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
        ?int $reminderLeadMinutes = null,
    ): array {
        $durationMinutes = max(1, $durationMinutes);
        $endsAt = Carbon::instance($startsAt->toDateTime())->copy()->addMinutes($durationMinutes);
        $startsAt = Carbon::instance($startsAt->toDateTime())->copy();

        $assignee ??= $responder;

        return DB::transaction(function () use ($chat, $responder, $assignee, $triggerMessage, $serviceName, $startsAt, $endsAt, $durationMinutes, $clientNote, $reminderLeadMinutes): array {
            $existing = CalendarEvent::query()
                ->where('trigger_message_id', $triggerMessage->id)
                ->where('source', CalendarEvent::SOURCE_AI_AUTO)
                ->first();
            if ($existing !== null) {
                $reminder = $this->syncReminderForEvent(
                    $existing,
                    $chat,
                    $responder,
                    $serviceName,
                    $startsAt,
                    $reminderLeadMinutes,
                );

                return ['event' => $existing, 'reminder' => $reminder];
            }

            $leadMinutes = $this->reminderSettings->resolveLeadMinutes($reminderLeadMinutes);

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
                        'reminder_lead_minutes' => $leadMinutes,
                    ],
                ],
            ]);

            $reminder = $this->syncReminderForEvent(
                $event,
                $chat,
                $responder,
                $serviceName,
                $startsAt,
                $reminderLeadMinutes,
            );

            return ['event' => $event, 'reminder' => $reminder];
        });
    }

    public function syncReminderForEvent(
        CalendarEvent $event,
        Chat $chat,
        User $responder,
        string $serviceName,
        CarbonInterface $startsAt,
        ?int $reminderLeadMinutes = null,
    ): ?ScheduledMessage {
        if (! $this->reminderSettings->enabled()) {
            return null;
        }

        $leadMinutes = $this->resolveLeadMinutesForEvent($event, $reminderLeadMinutes);
        $reminderAt = Carbon::instance($startsAt->toDateTime())->copy()->subMinutes($leadMinutes);

        $existing = ScheduledMessage::query()
            ->where('calendar_event_id', $event->id)
            ->where('purpose', ScheduledMessage::PURPOSE_APPOINTMENT_REMINDER)
            ->where('status', ScheduledMessage::STATUS_PENDING)
            ->first();

        if (! $reminderAt->isFuture()) {
            $existing?->delete();

            return null;
        }

        $body = $this->reminderBody($serviceName, $startsAt);
        $payload = [
            'body' => $body,
            'display_body' => $body,
            'scheduled_at' => $reminderAt,
            'user_id' => $responder->id,
        ];

        if ($existing !== null) {
            $existing->forceFill($payload)->save();

            return $existing;
        }

        return ScheduledMessage::create([
            'chat_id' => $chat->id,
            'whatsapp_session_id' => $chat->whatsapp_session_id,
            'user_id' => $responder->id,
            'calendar_event_id' => $event->id,
            'purpose' => ScheduledMessage::PURPOSE_APPOINTMENT_REMINDER,
            'status' => ScheduledMessage::STATUS_PENDING,
            ...$payload,
        ]);
    }

    private function resolveLeadMinutesForEvent(CalendarEvent $event, ?int $requestedMinutes): int
    {
        if ($requestedMinutes !== null) {
            return $this->reminderSettings->resolveLeadMinutes($requestedMinutes);
        }

        $stored = data_get($event->metadata, 'ai.reminder_lead_minutes');
        if (is_numeric($stored)) {
            return $this->reminderSettings->resolveLeadMinutes((int) $stored);
        }

        return $this->reminderSettings->leadTimeMinutes();
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
