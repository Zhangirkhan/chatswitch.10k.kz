<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\AiResponseLog;
use App\Models\Chat;
use App\Models\Message;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\Calendar\AppointmentBookingService;
use App\Services\Calendar\AppointmentReminderSettings;
use App\Services\Calendar\CalendarAvailabilityService;
use App\Services\Knowledge\ProductMessageAttachmentService;
use App\Support\ClientMessageHeuristics;
use Carbon\Carbon;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class AiReplyGenerator
{
    public function __construct(
        private readonly PromptBuilder $promptBuilder,
        private readonly OpenAiChatService $openAi,
        private readonly AiAppointmentIntentService $appointmentIntent,
        private readonly CalendarAvailabilityService $availability,
        private readonly AppointmentBookingService $bookingService,
        private readonly AppointmentReminderSettings $appointmentReminderSettings,
        private readonly ProductMessageAttachmentService $productAttachments,
    ) {}

    /**
     * @return array{reply: string, prompt_hash: string, metadata?: array<string, mixed>}
     */
    public function generate(Chat $chat, User $responder, ?Message $triggerMessage, ?AiResponseLog $log = null): array
    {
        $clientQuestion = trim((string) ($triggerMessage?->body ?? ''));

        if ($triggerMessage !== null) {
            $appointment = $this->appointmentIntent->detect($chat, $responder, $triggerMessage);
            if ($appointment !== null) {
                return $this->handleAppointmentIntent($chat, $responder, $triggerMessage, $appointment, $log);
            }
        }

        $built = $this->promptBuilder->build($chat, $responder, $clientQuestion, $chat->company_id ?? $responder->company_id);
        $reply = trim($this->openAi->chat($built['messages'], 0.35, 700));
        $reply = $this->sanitizeReply($reply);
        $reply = $this->normalizeCurrency($reply);
        $parsed = $this->productAttachments->stripAttachMarker($reply);
        $reply = $parsed['reply'];
        if ($triggerMessage !== null) {
            $reply = $this->normalizeGreetingReply($chat, $triggerMessage, $reply);
        }
        $this->assertSafeReply($reply);

        $metadata = [];
        if ($parsed['product_id'] !== null) {
            $product = $this->productAttachments->findForChat($chat, $parsed['product_id']);
            if ($product !== null) {
                $metadata['product'] = $this->productAttachments->snapshot($product);
            }
        }

        $log?->forceFill([
            'prompt_hash' => $built['prompt_hash'],
            'model' => (string) config('services.openai.model', 'gpt-4o-mini'),
            'metadata' => [
                ...($log->metadata ?? []),
                'messages_count' => count($built['messages']),
                'product_attach_id' => $parsed['product_id'],
            ],
        ])->save();

        return [
            'reply' => $reply,
            'prompt_hash' => $built['prompt_hash'],
            'metadata' => $metadata,
        ];
    }

    /**
     * @return array{reply: string, prompt_hash: string, metadata?: array<string, mixed>}
     */
    private function handleAppointmentIntent(
        Chat $chat,
        User $responder,
        Message $triggerMessage,
        AppointmentIntent $intent,
        ?AiResponseLog $log,
    ): array {
        $promptHash = hash('sha256', json_encode([
            'appointment_intent' => [
                'chat_id' => $chat->id,
                'trigger_message_id' => $triggerMessage->id,
                'service_name' => $intent->serviceName,
                'starts_at' => $intent->startsAt,
                'duration_minutes' => $intent->durationMinutes,
                'complete' => $intent->isComplete(),
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        if (SystemSetting::getValue('module_calendar', 'on') !== 'on') {
            return $this->appointmentReply(
                'Сейчас календарь недоступен, поэтому я передам запись оператору, чтобы он всё проверил и подтвердил.',
                $promptHash,
                $log,
                ['status' => 'calendar_disabled'],
            );
        }

        if (! $intent->isComplete()) {
            $reply = $intent->clientReply !== ''
                ? $intent->clientReply
                : 'Подскажите, пожалуйста, услугу, дату и время записи, чтобы я смогла всё зафиксировать.';

            return $this->appointmentReply($reply, $promptHash, $log, [
                'status' => 'needs_more_details',
                'missing_fields' => $intent->missingFields,
            ]);
        }

        try {
            $startsAt = Carbon::parse((string) $intent->startsAt);
        } catch (Throwable) {
            return $this->appointmentReply(
                'Подскажите, пожалуйста, дату и время записи ещё раз, чтобы я точно всё зафиксировала.',
                $promptHash,
                $log,
                ['status' => 'invalid_datetime'],
            );
        }

        if (! $startsAt->isFuture()) {
            return $this->appointmentReply(
                'Это время уже прошло. Подскажите, пожалуйста, другую дату и время для записи.',
                $promptHash,
                $log,
                ['status' => 'past_datetime'],
            );
        }

        $durationMinutes = $intent->durationMinutes ?? 60;
        $endsAt = $startsAt->copy()->addMinutes($durationMinutes);
        $conflict = $this->availability->firstConflict($responder, $startsAt, $endsAt);
        if ($conflict !== null) {
            return $this->appointmentReply(
                'На это время уже есть запись. Я передам оператору, чтобы он уточнил свободное окно и подтвердил запись.',
                $promptHash,
                $log,
                ['status' => 'conflict', 'conflict_event_id' => $conflict['id'] ?? null],
            );
        }

        $booking = $this->bookingService->book(
            $chat,
            $responder,
            $triggerMessage,
            (string) $intent->serviceName,
            $startsAt,
            $durationMinutes,
            $intent->clientNote,
            null,
            $intent->reminderLeadMinutes,
        );

        $reply = $this->defaultBookingConfirmation(
            (string) $intent->serviceName,
            $startsAt,
            $intent->reminderLeadMinutes,
        );

        return $this->appointmentReply($reply, $promptHash, $log, [
            'status' => 'booked',
            'calendar_event_id' => $booking['event']->id,
            'scheduled_message_id' => $booking['reminder']?->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array{reply: string, prompt_hash: string, metadata?: array<string, mixed>}
     */
    private function appointmentReply(string $reply, string $promptHash, ?AiResponseLog $log, array $metadata): array
    {
        $reply = $this->sanitizeReply($reply);
        $reply = $this->normalizeCurrency($reply);
        $this->assertSafeReply($reply);

        $log?->forceFill([
            'prompt_hash' => $promptHash,
            'model' => (string) config('services.openai.model', 'gpt-4o-mini'),
            'metadata' => [
                ...($log->metadata ?? []),
                'appointment' => $metadata,
            ],
        ])->save();

        return [
            'reply' => $reply,
            'prompt_hash' => $promptHash,
            'metadata' => ['appointment' => $metadata],
        ];
    }

    private function defaultBookingConfirmation(string $serviceName, Carbon $startsAt, ?int $reminderLeadMinutes = null): string
    {
        $timezone = (string) config('app.timezone', 'UTC');
        $localStart = $startsAt->copy()->timezone($timezone);

        $suffix = $this->appointmentReminderSettings->clientReminderSuffixForBookingConfirmation($reminderLeadMinutes);

        return 'Записала вас на '.$serviceName.' '.$localStart->format('d.m в H:i').'.'.$suffix;
    }

    private function normalizeGreetingReply(Chat $chat, Message $trigger, string $reply): string
    {
        $triggerBody = trim((string) $trigger->body);
        if (! ClientMessageHeuristics::usedGreeting($triggerBody)) {
            return $reply;
        }

        $hasPriorOutbound = $chat->messages()
            ->where('direction', 'outbound')
            ->where('id', '<', $trigger->id)
            ->exists();

        if ($hasPriorOutbound) {
            return $reply;
        }

        if (ClientMessageHeuristics::isShortGreetingOnly($triggerBody)) {
            if (ClientMessageHeuristics::isGenericStubReply($reply) || trim($reply) === '') {
                return 'Здравствуйте! Подскажите, чем можем помочь?';
            }
        }

        $trimmed = trim($reply);
        if ($trimmed === '' || ClientMessageHeuristics::isGenericStubReply($trimmed)) {
            return 'Здравствуйте! Подскажите, чем можем помочь?';
        }

        if (! ClientMessageHeuristics::usedGreeting($trimmed)) {
            return 'Здравствуйте! '.$trimmed;
        }

        return $trimmed;
    }

    private function sanitizeReply(string $reply): string
    {
        $reply = preg_replace('/^```(?:text|markdown)?\s*|\s*```$/iu', '', $reply) ?: $reply;
        $reply = trim($reply, " \t\n\r\0\x0B\"'");

        return Str::limit($reply, 4000, '...');
    }

    private function normalizeCurrency(string $reply): string
    {
        return preg_replace('/\b(?:руб(?:\.|лей|ля|ль|ли|лях|лями)?|₽)\b/iu', '₸', $reply) ?: $reply;
    }

    private function assertSafeReply(string $reply): void
    {
        if (trim($reply) === '') {
            throw new RuntimeException('AI safety check: пустой ответ.');
        }

        $lower = mb_strtolower($reply);
        $forbidden = [
            'я ai',
            'я ии',
            'я искусственный интеллект',
            'как ai',
            'как ии',
            'системная инструкция',
            'system prompt',
        ];

        foreach ($forbidden as $needle) {
            if (str_contains($lower, $needle)) {
                throw new RuntimeException('AI safety check: ответ раскрывает AI или служебный контекст.');
            }
        }
    }
}
