<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\Chat;
use App\Models\Message;
use Illuminate\Support\Str;

/**
 * Не отвечать AI на входящие от других ботов / автоответчиков (петля bot↔bot).
 */
final class AutomatedPeerReplyGuard
{
    /** @var list<string> */
    private const BOT_PHRASE_MARKERS = [
        'специализируюсь только на',
        'я специализируюсь',
        'автоответ',
        'автоматическ',
        'автоматическое сообщение',
        'this is an automated',
        'i am a bot',
        'я бот',
        'chatbot',
        'do not reply to this',
        'не отвечайте на это сообщение',
        'нажмите 1',
        'выберите пункт меню',
    ];

    public function shouldSuppress(Chat $chat, Message $trigger): bool
    {
        if ($trigger->direction !== 'inbound' || $chat->is_group) {
            return false;
        }

        $body = $this->normalized((string) $trigger->body);
        if ($body === '') {
            return false;
        }

        if ($this->matchesBotPhrase($body)) {
            return true;
        }

        if ($this->isHumanAcknowledgement($body)) {
            return false;
        }

        if ($this->hasRepeatedInboundBody($chat, $body)) {
            return true;
        }

        return $this->detectPingPongLoop($chat);
    }

    public function reason(Chat $chat, Message $trigger): string
    {
        $body = $this->normalized((string) $trigger->body);

        if ($this->matchesBotPhrase($body)) {
            return 'похоже на автоответ другого бота';
        }

        if ($this->isHumanAcknowledgement($body)) {
            return 'короткое подтверждение от клиента';
        }

        if ($this->hasRepeatedInboundBody($chat, $body)) {
            return 'повторяющееся входящее сообщение';
        }

        return 'слишком частые AI-ответы в диалоге (возможна петля bot↔bot)';
    }

    private function matchesBotPhrase(string $body): bool
    {
        foreach (self::BOT_PHRASE_MARKERS as $marker) {
            if (str_contains($body, $marker)) {
                return true;
            }
        }

        return false;
    }

    private function hasRepeatedInboundBody(Chat $chat, string $body): bool
    {
        if (mb_strlen($body) < 24) {
            return false;
        }

        return Message::query()
            ->where('chat_id', $chat->id)
            ->where('direction', 'inbound')
            ->whereRaw('LOWER(TRIM(body)) = ?', [$body])
            ->where('message_timestamp', '>=', now()->subMinutes(45))
            ->count() >= 2;
    }

    private function detectPingPongLoop(Chat $chat): bool
    {
        $recent = Message::query()
            ->where('chat_id', $chat->id)
            ->whereIn('direction', ['inbound', 'outbound'])
            ->where('message_timestamp', '>=', now()->subMinutes(10))
            ->orderByDesc('message_timestamp')
            ->orderByDesc('id')
            ->limit(14)
            ->get(['direction', 'metadata', 'body']);

        if ($recent->count() < 6) {
            return false;
        }

        $aiOutbound = $recent->filter(
            fn (Message $message): bool => $message->direction === 'outbound'
                && data_get($message->metadata, 'ai.generated') === true,
        )->count();

        if ($aiOutbound < 3) {
            return false;
        }

        $inbound = $recent->where('direction', 'inbound')->values();
        if ($inbound->count() < 3) {
            return false;
        }

        $uniqueInbound = $inbound
            ->map(fn (Message $message): string => $this->normalized((string) $message->body))
            ->unique()
            ->count();

        return $uniqueInbound <= 1;
    }

    private function isHumanAcknowledgement(string $body): bool
    {
        $body = $this->normalized($body);
        if ($body === '') {
            return false;
        }

        if (preg_match('/^(?:спасибо|благодарю|thanks|thank you|thank u|мерси|иә рахмет|рахмет|рақмет|ракмет|жарайды|ок|ok|okay|хорошо|понятно|ясно|иә|иа)(?:[!.…,\s]|$)/u', $body) === 1) {
            return true;
        }

        return mb_strlen($body) <= 16
            && preg_match('/^(?:да|нет)$/u', $body) === 1;
    }

    private function normalized(string $body): string
    {
        return Str::of(mb_strtolower(trim($body)))
            ->replaceMatches('/\s+/u', ' ')
            ->toString();
    }
}
