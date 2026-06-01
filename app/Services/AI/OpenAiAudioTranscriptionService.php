<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\Message;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class OpenAiAudioTranscriptionService
{
    public function __construct(
        private readonly WhisperTranscriptionOptionsResolver $optionsResolver,
        private readonly AiUsageRecorder $usageRecorder,
    ) {}

    public function transcribe(
        string $absoluteFilePath,
        string $filename,
        ?Message $message = null,
        ?AiUsageOptions $usage = null,
    ): string {
        if ($message !== null) {
            $options = $this->optionsResolver->resolve($message);

            return $this->transcribeWithOptions($absoluteFilePath, $filename, $options, $usage);
        }

        $language = trim((string) config('services.openai.whisper_language', ''))
            ?: trim((string) config('accel.whisper_default_language', 'auto'));
        $resolvedLanguage = ($language !== '' && $language !== 'auto') ? $language : null;

        return $this->transcribeWithOptions($absoluteFilePath, $filename, [
            'language' => $resolvedLanguage,
            'prompt' => trim((string) config('accel.whisper_prompt_auto', '')),
        ], $usage);
    }

    /**
     * @param  array{language: string|null, prompt: string}  $options
     */
    public function transcribeWithOptions(
        string $absoluteFilePath,
        string $filename,
        array $options,
        ?AiUsageOptions $usage = null,
    ): string {
        $apiKey = (string) config('services.openai.api_key');
        $baseUrl = rtrim((string) config('services.openai.base_url', 'https://api.openai.com/v1'), '/');
        $model = (string) config('accel.whisper_model', 'whisper-1');
        $timeout = (int) config('services.openai.transcribe_timeout', 180);

        if ($apiKey === '') {
            throw new RuntimeException('OPENAI_API_KEY не задан в .env (services.openai.api_key).');
        }

        if (! is_readable($absoluteFilePath)) {
            throw new RuntimeException('Аудиофайл недоступен для чтения: '.$absoluteFilePath);
        }

        $request = Http::baseUrl($baseUrl)
            ->withToken($apiKey)
            ->acceptJson()
            ->timeout($timeout)
            ->attach('file', fopen($absoluteFilePath, 'r'), $filename);

        $payload = [
            'model' => $model,
            'response_format' => 'verbose_json',
        ];

        $language = $options['language'] ?? null;
        if (is_string($language) && $language !== '') {
            $payload['language'] = $language;
        }

        $prompt = trim((string) ($options['prompt'] ?? ''));
        if ($prompt !== '') {
            $payload['prompt'] = $prompt;
        }

        try {
            $response = $request->post('/audio/transcriptions', $payload)->throw();
        } catch (ConnectionException $e) {
            throw new RuntimeException('Не удалось подключиться к OpenAI Whisper: '.$e->getMessage(), 0, $e);
        } catch (RequestException $e) {
            $body = (string) ($e->response?->body() ?? '');
            throw new RuntimeException('OpenAI Whisper вернул ошибку: '.mb_substr($body, 0, 500), 0, $e);
        }

        $data = $response->json();
        $text = $data['text'] ?? null;
        if (! is_string($text) || trim($text) === '') {
            throw new RuntimeException('OpenAI Whisper вернул пустой транскрипт.');
        }

        if ($usage !== null) {
            $duration = (float) ($data['duration'] ?? 0);
            $this->usageRecorder->recordWhisper(
                $usage->scenario,
                $usage->companyId,
                $model,
                max(1, (int) round($duration)),
            );
        }

        return trim($text);
    }
}
