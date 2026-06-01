<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Message;
use App\Models\MessageMedia;
use App\Models\MessageTranscript;
use Illuminate\Support\Facades\Storage;

final class MessageTranscriptStorageService
{
    public function storeAudioText(
        Message $message,
        MessageMedia $media,
        string $text,
        MessageTranscript $transcript,
    ): MessageTranscript {
        $model = (string) config('accel.whisper_model', 'whisper-1');
        $textDiskPath = $this->storeTextFile($message, $text);

        $transcript->forceFill([
            'text' => $text,
            'model' => $model,
            'source_mime' => $media->mime_type,
            'source_filename' => $media->filename,
            'text_disk_path' => $textDiskPath,
            'status' => MessageTranscript::STATUS_COMPLETED,
            'error_message' => null,
            'completed_at' => now(),
        ])->save();

        return $transcript;
    }

    private function storeTextFile(Message $message, string $text): ?string
    {
        $path = sprintf(
            'message-transcripts/%s/%d.txt',
            now()->format('Y/m'),
            $message->id,
        );

        if (! Storage::disk('local')->put($path, $text)) {
            return null;
        }

        return $path;
    }
}
