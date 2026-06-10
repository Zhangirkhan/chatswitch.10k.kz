<?php

declare(strict_types=1);

namespace App\Services\AI\Locale;

use App\Models\Chat;
use App\Models\Message;
use App\Support\MessageInboundText;

final class ChatInboundLocaleResolver
{
    private const DEFAULT_LIMIT = 3;

    public function __construct(
        private readonly KazakhstanLocaleDetector $detector,
    ) {}

    public function resolve(Chat $chat, ?Message $trigger = null, int $limit = self::DEFAULT_LIMIT): KazakhstanLocaleProfile
    {
        $samples = $this->recentInboundTexts($chat, $trigger, $limit);

        if ($samples === []) {
            return KazakhstanLocaleProfile::neutralRussian();
        }

        return $this->detector->detectFromSamples($samples);
    }

    /**
     * @return list<string>
     */
    public function recentInboundTexts(Chat $chat, ?Message $trigger, int $limit): array
    {
        $limit = max(1, $limit);
        $triggerId = $trigger?->id;

        $messages = Message::query()
            ->where('chat_id', $chat->id)
            ->where('direction', 'inbound')
            ->when($triggerId !== null, fn ($query) => $query->where('id', '<=', $triggerId))
            ->orderByDesc('message_timestamp')
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['id', 'body', 'type', 'metadata']);

        $samples = $messages
            ->map(fn (Message $message): string => trim(MessageInboundText::forMessage($message)))
            ->filter(fn (string $text): bool => $text !== '')
            ->values()
            ->all();

        if ($trigger !== null) {
            $triggerText = trim(MessageInboundText::forMessage($trigger));
            if ($triggerText !== '' && ! in_array($triggerText, $samples, true)) {
                array_unshift($samples, $triggerText);
                $samples = array_slice($samples, 0, $limit);
            }
        }

        return $samples;
    }
}
