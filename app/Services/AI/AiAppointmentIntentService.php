<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\Chat;
use App\Models\Message;
use App\Models\Service;
use App\Models\User;
use App\Support\MessageInboundText;
use App\Support\OperatorSignature;
use App\Support\ReminderLeadTimeParser;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

final class AiAppointmentIntentService
{
    private const HISTORY_LIMIT = 12;

    private const DEFAULT_DURATION_MINUTES = 60;

    public function __construct(
        private readonly OpenAiChatService $openAi,
        private readonly ReminderLeadTimeParser $reminderLeadParser,
        private readonly ConversationAppointmentResolver $conversationAppointments,
    ) {}

    public function shouldAnalyze(Chat $chat, ?Message $triggerMessage): bool
    {
        if ($triggerMessage === null) {
            return false;
        }

        return $this->conversationAppointments->shouldTriggerAppointmentAnalysis($chat, $triggerMessage);
    }

    public function detect(Chat $chat, User $responder, Message $triggerMessage): ?AppointmentIntent
    {
        if (! $this->shouldAnalyze($chat, $triggerMessage)) {
            return null;
        }

        try {
            $data = $this->openAi->chatJson(
                $this->messages($chat, $responder, $triggerMessage),
                0.1,
                700,
                new AiUsageOptions('appointment_intent', $chat->company_id ?? $responder->company_id),
            );
        } catch (Throwable $e) {
            Log::warning('[ai-booking] failed to detect appointment intent', [
                'chat_id' => $chat->id,
                'trigger_message_id' => $triggerMessage->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        $intent = AppointmentIntent::fromArray($data);
        if (! $intent->isAppointmentRequest) {
            return null;
        }

        $intent = $this->withCatalogDuration($intent, $chat->company_id ?? $responder->company_id);

        return $this->withReminderLeadTime($intent, $chat, $triggerMessage);
    }

    /**
     * @return array<int, array{role: 'system'|'user', content: string}>
     */
    private function messages(Chat $chat, User $responder, Message $triggerMessage): array
    {
        $timezone = (string) config('app.timezone', 'UTC');
        $now = Carbon::now($timezone)->toIso8601String();
        $services = $this->serviceCatalog($chat->company_id ?? $responder->company_id);
        $history = $this->conversationHistory($chat);

        $schema = <<<'TXT'
Верни только JSON без Markdown:
{
  "is_appointment_request": boolean,
  "has_explicit_confirmation": boolean,
  "service_name": string|null,
  "starts_at": string|null,
  "duration_minutes": number|null,
  "missing_fields": string[],
  "client_reply": string,
  "client_note": string|null,
  "reminder_lead_minutes": number|null
}
TXT;

        $system = <<<PROMPT
Ты классифицируешь переписку для внутреннего календаря Accel.
Текущие дата и время: {$now}. Часовой пояс: {$timezone}.

Правила:
1. Считай запись подтверждённой только если клиент явно согласовал услугу, дату и время.
2. Если нет услуги, даты или времени — не подтверждай запись и перечисли недостающие поля.
3. Если клиент пишет "сегодня", "завтра" или похожую относительную дату, вычисли конкретную календарную дату от текущего времени выше.
4. starts_at всегда возвращай ISO-8601 с конкретной датой и часовым поясом приложения.
5. duration_minutes бери из каталога услуг. Если точной услуги нет — null (система подставит длительность по умолчанию).
6. Запись на замер окон/дверей, выезд на объект, монтаж, консультацию или демонстрацию — это тоже запись на услугу: service_name может быть свободной короткой формулировкой, даже если её нет в каталоге.
7. client_reply — короткое готовое сообщение клиенту на языке последнего сообщения клиента. В нём не используй "сегодня", "завтра", "послезавтра"; пиши конкретную дату.
8. Не выдумывай запись, если в переписке только интерес или вопрос о цене.
9. Если клиент просит напомнить/предупредить за N часов или минут до визита — заполни reminder_lead_minutes (только число минут: 30 = полчаса, 120 = 2 часа). Если не просил — null.
10. В client_reply при подтверждении записи кратко упомяни, что напомните за указанный клиентом интервал (если он просил).
11. Дата и время могут быть в разных сообщениях истории: если клиент сначала спросил про визит/покупку/получение на сегодня, а потом уточнил время (например «в 18» или «18:00-ге») — собери полную запись из всей истории и подтверди её.
12. Казахский язык: бүгін=сегодня, ертең=завтра, жазылу/жазу=запись, сағат/уақыт=время, «18-де»=в 18:00.
13. Не требуй слово «запись»: «подъехать», «забрать», «купить сегодня», «можно прийти», «когда удобно» + время — это тоже запись на визит.
14. Если компания спросила «когда удобно» / «во сколько», а клиент ответил временем или «да» — считай запись подтверждённой, если дата понятна из контекста.

Каталог услуг:
{$services}

{$schema}
PROMPT;

        return [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => "История переписки:\n{$history}\n\nПоследнее сообщение клиента:\n".trim(MessageInboundText::forMessage($triggerMessage))],
        ];
    }

    private function serviceCatalog(?int $companyId): string
    {
        if ($companyId === null) {
            return '- Каталог услуг не выбран.';
        }

        $services = Service::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->where('include_in_prompt', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['name', 'duration_minutes']);

        if ($services->isEmpty()) {
            return '- Активных услуг в базе знаний нет.';
        }

        return $services
            ->map(fn (Service $service): string => '- '.$service->name.($service->duration_minutes ? " ({$service->duration_minutes} мин.)" : ''))
            ->implode("\n");
    }

    private function conversationHistory(Chat $chat): string
    {
        return $chat->messages()
            ->with(['sentByUser:id,name', 'transcript'])
            ->whereIn('direction', ['inbound', 'outbound'])
            ->where(function ($query): void {
                $query->whereNotNull('body')
                    ->where('body', '!=', '')
                    ->orWhereHas('transcript', static fn ($q) => $q
                        ->where('status', 'completed')
                        ->where('text', '!=', ''));
            })
            ->orderByDesc('message_timestamp')
            ->orderByDesc('id')
            ->limit(self::HISTORY_LIMIT)
            ->get()
            ->reverse()
            ->map(function (Message $message): string {
                $effectiveBody = $message->direction === 'inbound'
                    ? MessageInboundText::forMessage($message)
                    : trim((string) $message->body);
                $body = Str::limit(OperatorSignature::strip($effectiveBody), 500, '...');
                $time = optional($message->message_timestamp)->format('Y-m-d H:i') ?? '';

                if ($message->direction === 'outbound') {
                    $name = $message->sentByUser?->name ?: 'Сотрудник';

                    return "[{$time}] {$name}: {$body}";
                }

                return "[{$time}] Клиент: {$body}";
            })
            ->implode("\n");
    }

    private function withCatalogDuration(AppointmentIntent $intent, ?int $companyId): AppointmentIntent
    {
        if ($intent->durationMinutes !== null || $companyId === null || $intent->serviceName === null) {
            return $intent;
        }

        /** @var Collection<int, Service> $services */
        $services = Service::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->get(['name', 'duration_minutes']);

        $needle = mb_strtolower($intent->serviceName);
        $service = $services->first(fn (Service $candidate): bool => str_contains($needle, mb_strtolower($candidate->name))
            || str_contains(mb_strtolower($candidate->name), $needle));

        return new AppointmentIntent(
            isAppointmentRequest: $intent->isAppointmentRequest,
            hasExplicitConfirmation: $intent->hasExplicitConfirmation,
            serviceName: $intent->serviceName,
            startsAt: $intent->startsAt,
            durationMinutes: $service?->duration_minutes ?: self::DEFAULT_DURATION_MINUTES,
            clientReply: $intent->clientReply,
            missingFields: $intent->missingFields,
            clientNote: $intent->clientNote,
            reminderLeadMinutes: $intent->reminderLeadMinutes,
        );
    }

    private function withReminderLeadTime(AppointmentIntent $intent, Chat $chat, Message $triggerMessage): AppointmentIntent
    {
        if ($intent->reminderLeadMinutes !== null) {
            return $intent;
        }

        $history = $this->conversationHistory($chat);
        $parsed = $this->reminderLeadParser->parseFromText($history."\n".trim(MessageInboundText::forMessage($triggerMessage)));
        if ($parsed === null) {
            return $intent;
        }

        return new AppointmentIntent(
            isAppointmentRequest: $intent->isAppointmentRequest,
            hasExplicitConfirmation: $intent->hasExplicitConfirmation,
            serviceName: $intent->serviceName,
            startsAt: $intent->startsAt,
            durationMinutes: $intent->durationMinutes,
            clientReply: $intent->clientReply,
            missingFields: $intent->missingFields,
            clientNote: $intent->clientNote,
            reminderLeadMinutes: $parsed,
        );
    }
}
