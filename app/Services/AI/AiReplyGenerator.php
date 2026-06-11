<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\AiResponseLog;
use App\Models\CalendarEvent;
use App\Models\Chat;
use App\Models\Message;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\AI\Locale\ChatInboundLocaleResolver;
use App\Services\AI\Locale\LocaleReplyGuard;
use App\Services\AI\Orchestrator\ClientMessageIntentDetector;
use App\Services\Calendar\AppointmentBookingService;
use App\Services\Calendar\AppointmentReminderSettings;
use App\Services\Calendar\CalendarAvailabilityService;
use App\Services\Knowledge\ProductMessageAttachmentService;
use App\Support\ClientMessageHeuristics;
use App\Support\LocalizedClientGreeting;
use App\Support\MessageInboundText;
use Carbon\Carbon;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class AiReplyGenerator
{
    public function __construct(
        private readonly PromptBuilder $promptBuilder,
        private readonly OpenAiChatService $openAi,
        private readonly OpenAiModelResolver $modelResolver,
        private readonly AiAppointmentIntentService $appointmentIntent,
        private readonly CalendarAvailabilityService $availability,
        private readonly AppointmentBookingService $bookingService,
        private readonly AppointmentReminderSettings $appointmentReminderSettings,
        private readonly ProductMessageAttachmentService $productAttachments,
        private readonly ChatInboundLocaleResolver $chatLocaleResolver,
        private readonly LocaleReplyGuard $localeReplyGuard,
        private readonly ChatConflictService $conflictService,
        private readonly ConversationAppointmentResolver $conversationAppointments,
        private readonly ClientMessageIntentDetector $clientIntents,
    ) {}

    /**
     * @return array{reply: string, prompt_hash: string, metadata?: array<string, mixed>}
     */
    public function generate(Chat $chat, User $responder, ?Message $triggerMessage, ?AiResponseLog $log = null): array
    {
        $clientQuestion = $triggerMessage !== null
            ? trim(MessageInboundText::forMessage($triggerMessage))
            : '';

        if ($triggerMessage !== null) {
            $body = mb_strtolower(trim(MessageInboundText::forMessage($triggerMessage)));
            if ($this->clientIntents->isOrderCompletionFeedback($body)) {
                return $this->handleOrderCompletionFeedback($chat, $triggerMessage, $log);
            }

            if ($this->conversationAppointments->isSupplementalDetailAfterBooking($chat, $triggerMessage)) {
                return $this->handleSupplementalBookingDetail($chat, $triggerMessage, $log);
            }

            // Skip standalone appointment-intent detection when the orchestrator already
            // handled this trigger message — prevents double-booking where the orchestrator
            // creates an appointment and AiAppointmentIntentService creates a second one.
            $orchestratorHandled = \App\Models\AiOrchestratorRun::query()
                ->where('trigger_message_id', $triggerMessage->id)
                ->whereIn('status', [\App\Models\AiOrchestratorRun::STATUS_COMPLETED, \App\Models\AiOrchestratorRun::STATUS_NEEDS_MANAGER])
                ->exists();

            if (! $orchestratorHandled) {
                $appointment = $this->appointmentIntent->detect($chat, $responder, $triggerMessage);
                if ($appointment !== null) {
                    return $this->handleAppointmentIntent($chat, $responder, $triggerMessage, $appointment, $log);
                }
            }

            $conflict = $this->conflictService->resolveForInbound($chat, $triggerMessage, $responder);
            if ($conflict !== null) {
                $reply = trim($conflict['reply']);
                $this->assertSafeReply($reply);

                $log?->forceFill([
                    'metadata' => [
                        ...($log->metadata ?? []),
                        'conflict_situation' => $conflict['situation']->situation,
                        'conflict_escalated' => $conflict['escalate'],
                    ],
                ])->save();

                return [
                    'reply' => $reply,
                    'prompt_hash' => hash('sha256', 'conflict:'.$conflict['situation']->situation),
                    'metadata' => [
                        'conflict' => [
                            'situation' => $conflict['situation']->situation,
                            'tier' => $conflict['situation']->tier,
                            'escalated' => $conflict['escalate'],
                        ],
                    ],
                ];
            }
        }

        $built = $this->promptBuilder->build($chat, $responder, $clientQuestion, $chat->company_id ?? $responder->company_id, $triggerMessage);
        $localeProfile = $this->chatLocaleResolver->resolve($chat, $triggerMessage);
        $maxReplyTokens = max(300, (int) config('services.openai.max_reply_tokens', 700));
        $companyId = $chat->company_id ?? $responder->company_id;
        $llmResult = $this->openAi->chatWithUsage(
            $built['messages'],
            0.35,
            $maxReplyTokens,
            new AiUsageOptions('ai_reply', $companyId),
        );
        $reply = trim($llmResult['content']);
        $reply = $this->sanitizeReply($reply);
        $reply = $this->localeReplyGuard->apply($reply, $localeProfile);
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
            'prompt_hash'       => $built['prompt_hash'],
            'model'             => $this->modelResolver->chatModel($companyId),
            'tokens_prompt'     => $llmResult['tokens_prompt'],
            'tokens_completion' => $llmResult['tokens_completion'],
            'metadata' => [
                ...($log->metadata ?? []),
                'messages_count'    => count($built['messages']),
                'product_attach_id' => $parsed['product_id'],
                'decision_manifest' => $built['manifest'] ?? [],
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
    private function handleOrderCompletionFeedback(
        Chat $chat,
        Message $triggerMessage,
        ?AiResponseLog $log,
    ): array {
        $reply = 'Спасибо за обратную связь! Рады, что всё понравилось. Если понадобится что-то ещё — пишите.';
        $promptHash = hash('sha256', 'order_completion:'.$chat->id.':'.$triggerMessage->id);

        $log?->forceFill([
            'metadata' => [
                ...($log->metadata ?? []),
                'order_completion_feedback' => true,
            ],
        ])->save();

        return $this->appointmentReply($reply, $promptHash, $log, [
            'status' => 'order_completion_feedback',
        ]);
    }

    /**
     * @return array{reply: string, prompt_hash: string, metadata?: array<string, mixed>}
     */
    private function handleSupplementalBookingDetail(
        Chat $chat,
        Message $triggerMessage,
        ?AiResponseLog $log,
    ): array {
        $reply = $this->conversationAppointments->supplementalDeliveryReply($chat, $triggerMessage);
        $promptHash = hash('sha256', 'supplemental_delivery:'.$chat->id.':'.$triggerMessage->id);

        $booking = $this->conversationAppointments->findMatchingChatBooking($chat, $triggerMessage);
        if ($booking instanceof CalendarEvent) {
            $note = trim(MessageInboundText::forMessage($triggerMessage));
            $description = trim((string) $booking->description);
            $booking->forceFill([
                'description' => $description === ''
                    ? 'Адрес доставки: '.$note
                    : $description."\nАдрес доставки: ".$note,
                'trigger_message_id' => $triggerMessage->id,
            ])->save();
        }

        return $this->appointmentReply($reply, $promptHash, $log, [
            'status' => 'delivery_detail_added',
            'calendar_event_id' => $booking?->id,
        ]);
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
        $existingChatBooking = $this->conversationAppointments->findMatchingChatBooking($chat, $triggerMessage);
        $conflict = $this->availability->firstConflict($responder, $startsAt, $endsAt);
        if ($conflict !== null) {
            if ($existingChatBooking !== null && (int) ($conflict['id'] ?? 0) === $existingChatBooking->id) {
                return $this->handleSupplementalBookingDetail($chat, $triggerMessage, $log);
            }

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
            'model' => $this->modelResolver->chatModel($log->chat?->company_id),
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

        $suffix = $this->appointmentReminderSettings->clientReminderSuffixForBookingConfirmation($startsAt, $reminderLeadMinutes);

        return 'Записала вас на '.$serviceName.' '.$localStart->format('d.m в H:i').'.'.$suffix;
    }

    private function normalizeGreetingReply(Chat $chat, Message $trigger, string $reply): string
    {
        $triggerBody = trim(MessageInboundText::forMessage($trigger));
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

        $localeProfile = $this->chatLocaleResolver->resolve($chat, $trigger);

        if (ClientMessageHeuristics::isShortGreetingOnly($triggerBody)) {
            if (ClientMessageHeuristics::isGenericStubReply($reply) || trim($reply) === '') {
                return LocalizedClientGreeting::defaultFirstReply($localeProfile);
            }
        }

        $trimmed = trim($reply);
        if ($trimmed === '' || ClientMessageHeuristics::isGenericStubReply($trimmed)) {
            return LocalizedClientGreeting::defaultFirstReply($localeProfile);
        }

        if (! ClientMessageHeuristics::usedGreeting($trimmed)) {
            return LocalizedClientGreeting::prependGreeting($localeProfile, $trimmed);
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
