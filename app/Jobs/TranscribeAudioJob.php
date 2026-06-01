<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Events\NewMessageReceived;
use App\Models\Message;
use App\Models\MessageMedia;
use App\Services\AI\ChatDepartmentRoutingService;
use App\Services\AI\InboundAiDispatchService;
use App\Services\AI\WhisperTranscriptionOptionsResolver;
use App\Services\AI\OpenAiAudioTranscriptionService;
use App\Services\MessageTranscriptService;
use App\Services\MessageTranscriptStorageService;
use App\Support\VoiceInboundHelper;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

final class TranscribeAudioJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $uniqueFor = 3600;

    /** @var list<int> */
    public array $backoff = [5, 10, 20, 40, 60];

    private const BODY_SYNC_LIMIT = 2000;

    public function __construct(public readonly int $messageId) {}

    public function uniqueId(): string
    {
        return 'transcribe-audio-'.$this->messageId;
    }

    public function handle(
        OpenAiAudioTranscriptionService $transcription,
        MessageTranscriptStorageService $transcriptStorage,
        MessageTranscriptService $transcriptService,
        InboundAiDispatchService $aiDispatch,
        ChatDepartmentRoutingService $departmentRouting,
        WhisperTranscriptionOptionsResolver $whisperOptions,
    ): void {
        if (! VoiceInboundHelper::canTranscribe()) {
            return;
        }

        $startedAt = microtime(true);

        $message = Message::query()
            ->with(['media', 'transcript', 'chat'])
            ->find($this->messageId);

        if ($message === null) {
            return;
        }

        if ($message->direction !== 'inbound' || ! VoiceInboundHelper::isVoiceType((string) $message->type)) {
            return;
        }

        if (VoiceInboundHelper::hasUsableTranscript($message)) {
            $this->afterTranscriptReady($message, $aiDispatch, $departmentRouting, $startedAt, 'already_completed');

            return;
        }

        $transcript = $message->transcript ?? $transcriptService->ensurePending($message);
        $transcriptService->markProcessing($transcript);
        $this->broadcastMessage($message);

        $durationSkip = VoiceInboundHelper::durationSkipReason($message);
        if ($durationSkip['skip']) {
            $transcriptService->markSkipped($transcript, (string) $durationSkip['reason']);
            $this->logAudit('skipped', $message, $startedAt, ['reason' => $durationSkip['reason']]);
            $this->broadcastMessage($message->fresh(['transcript', 'media']));

            return;
        }

        $media = $this->resolveAudioMedia($message);
        if ($media === null) {
            $this->release(5);

            return;
        }

        $absolutePath = Storage::disk('local')->path($media->disk_path);
        if (! is_file($absolutePath)) {
            $this->release(5);

            return;
        }

        $filename = $media->filename ?: basename($media->disk_path);
        $fileSize = (int) ($media->file_size ?: filesize($absolutePath) ?: 0);

        $whisperOpts = $whisperOptions->resolve($message);

        $this->logAudit('started', $message, $startedAt, [
            'file_size' => $fileSize,
            'mime' => $media->mime_type,
            'whisper_language' => $whisperOpts['language'],
        ]);

        try {
            $text = $transcription->transcribeWithOptions(
                $absolutePath,
                $filename,
                $whisperOpts,
                new \App\Services\AI\AiUsageOptions('whisper', $message->chat?->company_id),
            );

            $text = $this->retryTranscriptionIfNeeded(
                $transcription,
                $absolutePath,
                $filename,
                $message,
                $text,
                $whisperOpts,
                $whisperOptions,
            );

        } catch (Throwable $e) {
            $transcriptService->markFailed($transcript, $e->getMessage());
            $this->logAudit('failed', $message, $startedAt, [
                'error' => $e->getMessage(),
                'file_size' => $fileSize,
            ]);
            $this->broadcastMessage($message->fresh(['transcript', 'media']));

            throw $e;
        }

        $transcriptStorage->storeAudioText($message, $media, $text, $transcript);
        $message->refresh();
        $message->load(['transcript', 'chat']);

        $this->syncMessageBodyFromTranscript($message, $text);
        $this->logAudit('succeeded', $message, $startedAt, [
            'file_size' => $fileSize,
            'text_length' => mb_strlen($text),
        ]);

        $message->refresh();
        $this->broadcastMessage($message->load([
            'media',
            'transcript',
            'sentByUser',
            'whatsappSession',
            'reactions.user:id,name',
            'quotedMessage:id,whatsapp_message_id,direction,type,body,sender_name,sender_phone,sent_by_user_id',
            'quotedMessage.sentByUser:id,name',
            'quotedMessage.media:id,message_id,mime_type,filename',
        ]));

        $this->afterTranscriptReady($message->fresh(['chat', 'transcript']), $aiDispatch, $departmentRouting, $startedAt, 'whisper');
    }

    public function viaQueue(): string
    {
        return 'transcription';
    }

    private function afterTranscriptReady(
        Message $message,
        InboundAiDispatchService $aiDispatch,
        ChatDepartmentRoutingService $departmentRouting,
        float $startedAt,
        string $source,
    ): void {
        if ($message->chat !== null && VoiceInboundHelper::hasUsableTranscript($message)) {
            $departmentRouting->resolveAndAssignDepartment($message->chat, $message);
            $message->chat->refresh();
        }

        $this->dispatchAiIfNeeded($message, $aiDispatch);

        Log::debug('[transcribe-audio] pipeline finished', [
            'message_id' => $message->id,
            'source' => $source,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);
    }

    private function retryTranscriptionIfNeeded(
        OpenAiAudioTranscriptionService $transcription,
        string $absolutePath,
        string $filename,
        Message $message,
        string $text,
        array $whisperOpts,
        WhisperTranscriptionOptionsResolver $whisperOptions,
    ): string {
        $usage = new \App\Services\AI\AiUsageOptions('whisper', $message->chat?->company_id);
        $language = $whisperOpts['language'] ?? null;

        if (WhisperTranscriptionOptionsResolver::looksLikePromptEcho($text, (string) ($whisperOpts['prompt'] ?? ''))
            || WhisperTranscriptionOptionsResolver::looksLikeKazakhPromptEcho($text)) {
            $retryLanguage = $language === 'kk' ? 'ru' : ($language === 'ru' ? null : 'ru');
            Log::warning('[transcribe-audio] prompt echo detected, retrying', [
                'message_id' => $message->id,
                'first_text' => mb_substr($text, 0, 120),
                'retry_language' => $retryLanguage ?? 'auto',
            ]);

            return $transcription->transcribeWithOptions(
                $absolutePath,
                $filename,
                $whisperOptions->optionsForLanguage($retryLanguage),
                $usage,
            );
        }

        if (WhisperTranscriptionOptionsResolver::shouldRetryWithRussian($text, $language)) {
            Log::info('[transcribe-audio] kazakh transcript looks wrong for speech, retrying with ru', [
                'message_id' => $message->id,
                'first_text' => mb_substr($text, 0, 80),
            ]);

            return $transcription->transcribeWithOptions(
                $absolutePath,
                $filename,
                $whisperOptions->optionsForLanguage('ru'),
                $usage,
            );
        }

        if (WhisperTranscriptionOptionsResolver::shouldRetryWithKazakh($text, $language)) {
            Log::info('[transcribe-audio] weak cyrillic transcript, retrying with kk', [
                'message_id' => $message->id,
                'first_text' => mb_substr($text, 0, 80),
            ]);

            return $transcription->transcribeWithOptions(
                $absolutePath,
                $filename,
                $whisperOptions->optionsForLanguage('kk'),
                $usage,
            );
        }

        return $text;
    }

    private function resolveAudioMedia(Message $message): ?MessageMedia
    {
        /** @var MessageMedia|null $media */
        $media = $message->media->first();

        if ($media === null || trim((string) $media->disk_path) === '') {
            return null;
        }

        return $media;
    }

    private function syncMessageBodyFromTranscript(Message $message, string $text): void
    {
        if (trim((string) $message->body) !== '') {
            return;
        }

        $message->update([
            'body' => Str::limit(trim($text), self::BODY_SYNC_LIMIT, ''),
        ]);
    }

    private function dispatchAiIfNeeded(Message $message, InboundAiDispatchService $aiDispatch): void
    {
        if (VoiceInboundHelper::needsTranscriptionBeforeAi($message)) {
            return;
        }

        if (! VoiceInboundHelper::canAiReplyToVoice() && VoiceInboundHelper::isVoiceType((string) $message->type)) {
            return;
        }

        $aiDispatch->dispatchForInboundMessage($message);
    }

    private function broadcastMessage(Message $message): void
    {
        try {
            broadcast(new NewMessageReceived($message, (int) $message->chat_id));
        } catch (Throwable $e) {
            Log::warning('[transcribe-audio] broadcast failed', [
                'message_id' => $message->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function logAudit(string $event, Message $message, float $startedAt, array $extra = []): void
    {
        Log::info('[transcribe-audio] '.$event, [
            'message_id' => $message->id,
            'chat_id' => $message->chat_id,
            'company_id' => $message->chat?->company_id,
            'type' => $message->type,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ...$extra,
        ]);
    }
}
