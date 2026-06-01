<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Message;
use App\Models\MessageTranscript;

final class VoiceInboundHelper
{
    /** @var list<string> */
    private const VOICE_TYPES = ['ptt', 'voice', 'audio'];

    public static function isVoiceType(string $type): bool
    {
        return in_array(strtolower($type), self::VOICE_TYPES, true);
    }

    public static function canTranscribe(): bool
    {
        if (! filter_var(config('accel.transcribe_audio', true), FILTER_VALIDATE_BOOLEAN)) {
            return false;
        }

        return trim((string) config('services.openai.api_key', '')) !== '';
    }

    public static function canAiReplyToVoice(): bool
    {
        return filter_var(config('accel.ai_voice_replies', true), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Inbound voice without transcript: defer funnel / auto-reply until Whisper finishes.
     */
    public static function needsTranscriptionBeforeAi(Message $message): bool
    {
        if ($message->direction !== 'inbound' || ! self::isVoiceType((string) $message->type)) {
            return false;
        }

        if (! self::canTranscribe()) {
            return false;
        }

        return ! self::hasUsableTranscript($message);
    }

    public static function isVoiceWithoutContent(Message $message): bool
    {
        if (! self::isVoiceType((string) $message->type)) {
            return false;
        }

        return ! self::hasUsableTranscript($message);
    }

    public static function hasUsableTranscript(Message $message): bool
    {
        $message->loadMissing('transcript');
        $transcript = $message->transcript;
        if ($transcript === null) {
            return false;
        }

        if ($transcript->status === MessageTranscript::STATUS_COMPLETED) {
            return trim((string) $transcript->text) !== '';
        }

        return trim((string) $transcript->text) !== ''
            && in_array((string) $transcript->status, ['', MessageTranscript::STATUS_PENDING], true);
    }

    /**
     * @return array{skip: bool, reason: string|null}
     */
    public static function durationSkipReason(Message $message): array
    {
        $duration = self::voiceDurationSeconds($message);
        if ($duration === null) {
            return ['skip' => false, 'reason' => null];
        }

        $min = max(0, (int) config('accel.transcribe_min_duration_seconds', 1));
        $max = max($min, (int) config('accel.transcribe_max_duration_seconds', 600));

        if ($duration < $min) {
            return ['skip' => true, 'reason' => "Голосовое короче {$min} с — расшифровка пропущена."];
        }

        if ($duration > $max) {
            return ['skip' => true, 'reason' => "Голосовое длиннее {$max} с — расшифровка пропущена."];
        }

        return ['skip' => false, 'reason' => null];
    }

    public static function voiceDurationSeconds(Message $message): ?float
    {
        $duration = data_get($message->metadata, 'media.duration');
        if (! is_numeric($duration)) {
            return null;
        }

        $seconds = (float) $duration;

        return $seconds >= 0 ? $seconds : null;
    }
}
