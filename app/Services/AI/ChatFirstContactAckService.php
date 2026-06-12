<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\AiResponseLog;
use App\Models\Chat;
use App\Models\Message;
use App\Services\AI\Locale\ChatInboundLocaleResolver;
use App\Services\AI\Locale\KazakhstanLocaleProfile;
use App\Services\OutboundChatMessageDispatcher;
use App\Support\LocalizedClientGreeting;
use App\Support\MessageInboundText;
use App\Support\ClientMessageHeuristics;
use App\Support\VoiceInboundHelper;
use App\Support\WhatsappMessageType;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Generates and sends a contextual first-contact acknowledgment when the client
 * writes first and the chat has no prior outbound messages — regardless of ai_enabled
 * or ai_mode.
 */
final class ChatFirstContactAckService
{
    public const LOG_MODE = 'first_contact_ack';

    public function __construct(
        private readonly AutomatedPeerReplyGuard $automatedPeerGuard,
        private readonly ChatConflictService $conflictService,
        private readonly AiResponderResolver $responderResolver,
        private readonly ChatInboundLocaleResolver $localeResolver,
        private readonly OpenAiChatService $openAi,
        private readonly OutboundChatMessageDispatcher $dispatcher,
        private readonly WhatsappAiTypingService $typing,
    ) {}

    public function isEnabled(): bool
    {
        return (bool) config('ai.first_contact_ack.enabled', true);
    }

    public function shouldAttempt(Chat $chat, Message $trigger): bool
    {
        if (! $this->isEnabled()) {
            return false;
        }

        if ($chat->is_group || $chat->is_sandbox || $trigger->direction !== 'inbound') {
            return false;
        }

        if (! $this->inboundHasActionableContent($trigger)) {
            return false;
        }

        if ($this->hasOutboundMessages($chat)) {
            return false;
        }

        if ($this->conflictService->isAiPausedForConflict($chat)) {
            return false;
        }

        if ($this->automatedPeerGuard->shouldSuppress($chat, $trigger)) {
            return false;
        }

        if ($this->responderResolver->forChat($chat, $chat->funnel?->aiScenario) === null) {
            return false;
        }

        return ! $this->alreadyHandledTrigger($trigger);
    }

    public function willFullAutoPipelineSend(Chat $chat): bool
    {
        return $chat->ai_enabled && $chat->ai_mode !== 'draft';
    }

    public function generateAndSend(Chat $chat, Message $trigger): void
    {
        if (! $this->shouldAttempt($chat, $trigger)) {
            return;
        }

        if ((int) $this->latestInboundId($chat) !== (int) $trigger->id) {
            return;
        }

        $chat->loadMissing(['company:id,name', 'funnel.aiScenario', 'aiResponder', 'assignments.user', 'departments']);
        $responder = $this->responderResolver->forChat($chat, $chat->funnel?->aiScenario);
        if ($responder === null) {
            return;
        }

        $log = AiResponseLog::firstOrCreate(
            ['trigger_message_id' => $trigger->id, 'mode' => self::LOG_MODE],
            [
                'company_id' => $chat->company_id ?? $responder->company_id,
                'chat_id' => $chat->id,
                'user_id' => $responder->id,
                'status' => 'pending',
                'correlation_id' => Str::uuid()->toString(),
            ],
        );

        if ($log->message_id !== null || in_array($log->status, ['sent', 'generating'], true)) {
            return;
        }

        $claimed = AiResponseLog::query()
            ->whereKey($log->id)
            ->whereNull('message_id')
            ->whereIn('status', ['pending', 'failed'])
            ->update(['status' => 'generating', 'error' => null]);

        if ($claimed !== 1) {
            return;
        }

        $log->refresh();

        try {
            $generated = $this->typing->whileGenerating(
                $chat,
                fn (): array => $this->generateReply($chat, $trigger),
            );

            if ((int) $this->latestInboundId($chat) !== (int) $trigger->id) {
                $log->forceFill([
                    'status' => 'cancelled',
                    'error' => 'Stale first-contact ack discarded: newer inbound message arrived.',
                ])->save();

                return;
            }

            if ($this->hasOutboundMessages($chat)) {
                $log->forceFill([
                    'status' => 'cancelled',
                    'error' => 'First-contact ack skipped: outbound message already sent.',
                ])->save();

                return;
            }

            $reply = trim($generated['reply']);
            $this->assertSafeReply($reply);

            $message = $this->dispatcher->sendTextMessage($responder, $chat, [
                'message' => $reply,
                'display_message' => $reply,
                'metadata' => [
                    'ai' => [
                        'generated' => true,
                        'mode' => self::LOG_MODE,
                        'trigger_message_id' => $trigger->id,
                        'reply_as_company' => $this->responderResolver->replyAsCompany($chat),
                    ],
                ],
            ])->message;

            $log->forceFill([
                'message_id' => $message->id,
                'status' => 'sent',
                'prompt_hash' => $generated['prompt_hash'],
                'metadata' => [
                    ...($log->metadata ?? []),
                    ...($generated['metadata'] ?? []),
                ],
                'error' => null,
            ])->save();
        } catch (Throwable $e) {
            $isBlocked = str_contains($e->getMessage(), 'AI safety check');
            $log->forceFill([
                'status' => $isBlocked ? 'blocked' : 'failed',
                'error' => mb_substr($e->getMessage(), 0, 2000),
            ])->save();

            Log::warning('[first-contact-ack] failed', [
                'chat_id' => $chat->id,
                'trigger_message_id' => $trigger->id,
                'error' => $e->getMessage(),
            ]);

            if (! $isBlocked) {
                throw $e;
            }
        }
    }

    /**
     * @return array{reply: string, prompt_hash: string, metadata?: array<string, mixed>}
     */
    private function generateReply(Chat $chat, Message $trigger): array
    {
        $clientText = trim(MessageInboundText::forMessage($trigger));
        $locale = $this->localeResolver->resolve($chat, $trigger);
        $companyName = trim((string) ($chat->company?->name ?? '')) ?: 'компания';

        try {
            $messages = $this->buildPromptMessages($clientText, $companyName, $locale);
            $reply = trim($this->openAi->chat(
                $messages,
                (float) config('ai.first_contact_ack.temperature', 0.7),
                (int) config('ai.first_contact_ack.max_tokens', 200),
                new AiUsageOptions(self::LOG_MODE, $chat->company_id),
            ));

            if ($reply === '') {
                throw new RuntimeException('Пустой ответ от OpenAI.');
            }

            return [
                'reply' => $reply,
                'prompt_hash' => hash('sha256', json_encode($messages, JSON_UNESCAPED_UNICODE)),
                'metadata' => ['source' => 'llm'],
            ];
        } catch (Throwable $e) {
            Log::warning('[first-contact-ack] LLM fallback', [
                'chat_id' => $chat->id,
                'error' => $e->getMessage(),
            ]);

            $fallback = $this->fallbackReply($locale, $clientText);

            return [
                'reply' => $fallback,
                'prompt_hash' => hash('sha256', 'fallback:'.$fallback),
                'metadata' => ['source' => 'fallback'],
            ];
        }
    }

    /**
     * @return list<array{role: 'system'|'user', content: string}>
     */
    private function buildPromptMessages(string $clientText, string $companyName, KazakhstanLocaleProfile $locale): array
    {
        $languageHint = LocalizedClientGreeting::isKazakhPreferred($locale)
            ? 'Ответь на казахском или смешанном kk/ru, как принято в WhatsApp Казахстана.'
            : 'Ответь на русском языке.';

        $clientSnippet = $clientText !== ''
            ? "Сообщение клиента: «{$clientText}»"
            : 'Клиент отправил медиа или короткое обращение без текста.';

        $system = <<<PROMPT
Ты — вежливый оператор {$companyName} в WhatsApp.
{$languageHint}
Напиши ОДИН короткий ответ (1–3 предложения):
- естественно поприветствуй клиента с учётом его сообщения;
- подтверди, что обращение получено и передано команде;
- сообщи, что с клиентом свяжутся в ближайшее время (формулировку каждый раз меняй);
- не называй цены, не записывай на время, не обещай точный срок;
- не используй списки и шаблонные фразы вроде «Спасибо за интерес»;
- не упоминай AI, бота или автоматизацию.
PROMPT;

        return [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $clientSnippet],
        ];
    }

    private function fallbackReply(KazakhstanLocaleProfile $locale, string $clientText): string
    {
        $greeting = LocalizedClientGreeting::defaultFirstReply($locale);
        $variants = LocalizedClientGreeting::isKazakhPreferred($locale)
            ? [
                'Хабарламаңызды алдық, жақын арада менеджер хабарласады.',
                'Сұранысыңыз қабылданды — командамыз жақын уақытта байланысады.',
                'Жазғаныңызға рахмет, менеджер жақын арада жауап береді.',
            ]
            : [
                'Мы получили ваше сообщение — менеджер свяжется с вами в ближайшее время.',
                'Ваш запрос принят в работу, специалист ответит в ближайшее время.',
                'Спасибо за обращение! Команда свяжется с вами совсем скоро.',
            ];

        $tail = $variants[crc32($clientText) % count($variants)];

        if (ClientMessageHeuristics::usedGreeting($clientText)) {
            return $tail;
        }

        return trim($greeting.' '.$tail);
    }

    private function inboundHasActionableContent(Message $trigger): bool
    {
        if (VoiceInboundHelper::needsTranscriptionBeforeAi($trigger)) {
            return true;
        }

        if ($trigger->media()->exists()) {
            return true;
        }

        $type = strtolower(trim((string) $trigger->type));
        if ($type !== '' && $type !== 'chat') {
            return ! WhatsappMessageType::shouldIgnoreInbound($type);
        }

        return trim(MessageInboundText::forMessage($trigger)) !== '';
    }

    private function hasOutboundMessages(Chat $chat): bool
    {
        return Message::query()
            ->where('chat_id', $chat->id)
            ->where('direction', 'outbound')
            ->exists();
    }

    private function alreadyHandledTrigger(Message $trigger): bool
    {
        return AiResponseLog::query()
            ->where('trigger_message_id', $trigger->id)
            ->where('mode', self::LOG_MODE)
            ->whereIn('status', ['sent', 'generating', 'pending'])
            ->exists();
    }

    private function latestInboundId(Chat $chat): ?int
    {
        $id = Message::query()
            ->where('chat_id', $chat->id)
            ->where('direction', 'inbound')
            ->latest('message_timestamp')
            ->latest('id')
            ->value('id');

        return $id !== null ? (int) $id : null;
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
            'system prompt',
            'системная инструкция',
        ];

        foreach ($forbidden as $needle) {
            if (str_contains($lower, $needle)) {
                throw new RuntimeException('AI safety check: запрещённая формулировка.');
            }
        }
    }
}
