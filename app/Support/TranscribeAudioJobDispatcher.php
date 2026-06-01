<?php

declare(strict_types=1);

namespace App\Support;

use App\Jobs\TranscribeAudioJob;
use App\Models\Message;
use App\Services\MessageTranscriptService;

final class TranscribeAudioJobDispatcher
{
    public static function dispatchIfNeeded(Message $message): void
    {
        if (! VoiceInboundHelper::isVoiceType((string) $message->type) || ! VoiceInboundHelper::canTranscribe()) {
            return;
        }

        if (VoiceInboundHelper::hasUsableTranscript($message)) {
            return;
        }

        app(MessageTranscriptService::class)->ensurePending($message);
        TranscribeAudioJob::dispatch($message->id);
    }
}
