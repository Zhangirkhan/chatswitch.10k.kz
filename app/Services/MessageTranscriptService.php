<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Message;
use App\Models\MessageTranscript;
use Illuminate\Support\Str;

final class MessageTranscriptService
{
    public function ensurePending(Message $message): MessageTranscript
    {
        return MessageTranscript::query()->firstOrCreate(
            [
                'message_id' => $message->id,
                'kind' => MessageTranscript::KIND_AUDIO,
            ],
            [
                'text' => '',
                'status' => MessageTranscript::STATUS_PENDING,
            ],
        );
    }

    public function markProcessing(MessageTranscript $transcript): void
    {
        $transcript->forceFill([
            'status' => MessageTranscript::STATUS_PROCESSING,
            'started_at' => $transcript->started_at ?? now(),
            'error_message' => null,
        ])->save();
    }

    public function markCompleted(MessageTranscript $transcript, string $text): void
    {
        $transcript->forceFill([
            'text' => $text,
            'status' => MessageTranscript::STATUS_COMPLETED,
            'error_message' => null,
            'completed_at' => now(),
        ])->save();
    }

    public function markFailed(MessageTranscript $transcript, string $error): void
    {
        $transcript->forceFill([
            'status' => MessageTranscript::STATUS_FAILED,
            'error_message' => Str::limit(trim($error), 2000, ''),
            'completed_at' => now(),
        ])->save();
    }

    public function markSkipped(MessageTranscript $transcript, string $reason): void
    {
        $transcript->forceFill([
            'status' => MessageTranscript::STATUS_SKIPPED,
            'error_message' => Str::limit(trim($reason), 2000, ''),
            'completed_at' => now(),
        ])->save();
    }
}
