<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Message;
use App\Models\MessageTranscript;

final class MessageInboundText
{
    /**
     * Эффективный текст сообщения: для inbound voice приоритет у расшифровки.
     */
    public static function forMessage(Message $message, bool $voicePrefixWhenFromTranscript = false): string
    {
        if ($message->direction === 'inbound' && VoiceInboundHelper::isVoiceType((string) $message->type)) {
            $transcriptText = self::transcriptText($message);
            if ($transcriptText !== '') {
                if ($voicePrefixWhenFromTranscript) {
                    return '[голосовое] '.$transcriptText;
                }

                return $transcriptText;
            }
        }

        $body = trim((string) ($message->body ?? ''));
        if ($body !== '') {
            return $body;
        }

        $transcriptText = self::transcriptText($message);
        if ($transcriptText === '') {
            return '';
        }

        if ($voicePrefixWhenFromTranscript && VoiceInboundHelper::isVoiceType((string) $message->type)) {
            return '[голосовое] '.$transcriptText;
        }

        return $transcriptText;
    }

    private static function transcriptText(Message $message): string
    {
        $message->loadMissing('transcript');
        $transcript = $message->transcript;
        if ($transcript === null) {
            return '';
        }

        if ($transcript->status !== '' && $transcript->status !== MessageTranscript::STATUS_COMPLETED) {
            return '';
        }

        return trim((string) $transcript->text);
    }
}
