<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\Chat;
use App\Models\Message;
use App\Support\MessageInboundText;
use App\Support\MessageLanguageHeuristics;

final class ChatClientLanguageService
{
    public function detectForChat(Chat $chat): ?string
    {
        if ($chat->is_group) {
            return null;
        }

        $samples = Message::query()
            ->where('chat_id', $chat->id)
            ->where('direction', 'inbound')
            ->whereNotNull('body')
            ->orderByDesc('message_timestamp')
            ->orderByDesc('id')
            ->limit(20)
            ->get(['body'])
            ->map(fn (Message $message): string => MessageInboundText::forMessage($message))
            ->all();

        return MessageLanguageHeuristics::detectFromSamples($samples);
    }

    public function resolveOutgoingTarget(Chat $chat, string $draft): ?string
    {
        $draft = trim($draft);
        if ($draft === '') {
            return null;
        }

        $detected = $this->detectForChat($chat);
        if ($detected !== null) {
            if (MessageLanguageHeuristics::matches($detected, $draft)) {
                return null;
            }

            return $detected;
        }

        if (MessageLanguageHeuristics::matches(MessageLanguageHeuristics::LANG_RU, $draft)) {
            return MessageLanguageHeuristics::LANG_KK;
        }

        if (MessageLanguageHeuristics::matches(MessageLanguageHeuristics::LANG_KK, $draft)) {
            return MessageLanguageHeuristics::LANG_RU;
        }

        if (MessageLanguageHeuristics::matches(MessageLanguageHeuristics::LANG_EN, $draft)) {
            return MessageLanguageHeuristics::LANG_RU;
        }

        return $this->fallbackOutgoingTarget($draft);
    }

    private function fallbackOutgoingTarget(string $draft): ?string
    {
        $detected = MessageLanguageHeuristics::detectFromSamples([$draft]);

        return match ($detected) {
            MessageLanguageHeuristics::LANG_RU => MessageLanguageHeuristics::LANG_KK,
            MessageLanguageHeuristics::LANG_KK => MessageLanguageHeuristics::LANG_RU,
            MessageLanguageHeuristics::LANG_EN => MessageLanguageHeuristics::LANG_RU,
            default => MessageLanguageHeuristics::LANG_KK,
        };
    }
}
