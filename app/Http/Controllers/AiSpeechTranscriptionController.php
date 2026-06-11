<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Chat;
use App\Services\AI\AiUsageOptions;
use App\Services\AI\AudioTranscodeService;
use App\Services\AI\OpenAiAudioTranscriptionService;
use App\Services\AI\WhisperTranscriptionOptionsResolver;
use App\Support\AiSafeErrorMessage;
use App\Support\VoiceInboundHelper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

final class AiSpeechTranscriptionController extends Controller
{
    public function store(
        Request $request,
        OpenAiAudioTranscriptionService $transcription,
        WhisperTranscriptionOptionsResolver $optionsResolver,
        AudioTranscodeService $transcoder,
    ): JsonResponse {
        if (! VoiceInboundHelper::canTranscribe()) {
            return response()->json([
                'message' => 'Распознавание речи отключено.',
            ], 503);
        }

        $data = $request->validate([
            'audio' => [
                'required',
                'file',
                'max:10240',
                'mimetypes:audio/webm,audio/ogg,audio/mp4,audio/mpeg,audio/wav,audio/x-wav,audio/x-m4a,audio/aac,audio/x-caf,audio/amr,video/webm,video/mp4',
            ],
            'language' => ['nullable', 'string', 'in:auto,ru,kk,en'],
            'chat_id' => ['nullable', 'integer', 'exists:chats,id'],
        ]);

        $file = $request->file('audio');
        if (! $file instanceof UploadedFile) {
            return response()->json([
                'message' => 'Аудиофайл не получен.',
            ], 422);
        }

        $user = $request->user();
        $chatId = isset($data['chat_id']) ? (int) $data['chat_id'] : null;

        if ($chatId !== null) {
            $chat = Chat::query()->findOrFail($chatId);
            $this->authorize('view', $chat);
        }

        $filename = $file->getClientOriginalName() ?: 'dictation.webm';
        $path = $file->getRealPath();

        if ($path === false || ! is_readable($path)) {
            return response()->json([
                'message' => 'Не удалось прочитать аудиофайл.',
            ], 422);
        }

        $transcodedPath = null;
        $mimeType = (string) ($file->getMimeType() ?? '');

        if ($transcoder->needsTranscode($mimeType)) {
            $transcodedPath = $transcoder->transcodeToWebm($path);
            if ($transcodedPath === null) {
                return response()->json([
                    'message' => 'Не удалось преобразовать аудио. Установите ffmpeg или используйте другой браузер.',
                ], 422);
            }
            $path = $transcodedPath;
            $filename = 'dictation.webm';
        }

        $whisperOptions = $optionsResolver->resolveForDictation(
            $chatId,
            isset($data['language']) ? (string) $data['language'] : null,
        );

        try {
            $text = $transcription->transcribeWithOptions(
                $path,
                $filename,
                $whisperOptions,
                new AiUsageOptions('operator_dictation', $user?->company_id),
            );
        } catch (RuntimeException $e) {
            Log::warning('[ai-speech-transcription] failed', [
                'user_id' => $user?->id,
                'chat_id' => $chatId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => AiSafeErrorMessage::forUser(
                    $e->getMessage(),
                    $user?->hasRole('administrator') === true,
                ),
                'technical_error' => $user?->hasRole('administrator') === true ? $e->getMessage() : null,
            ], 502);
        } catch (Throwable $e) {
            Log::error('[ai-speech-transcription] unexpected failure', [
                'user_id' => $user?->id,
                'chat_id' => $chatId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Не удалось распознать речь.',
            ], 500);
        } finally {
            if ($transcodedPath !== null && is_file($transcodedPath)) {
                @unlink($transcodedPath);
            }
        }

        $text = trim($text);
        if ($text === '') {
            return response()->json([
                'message' => 'Речь не распознана.',
            ], 422);
        }

        return response()->json([
            'text' => $text,
        ]);
    }
}
