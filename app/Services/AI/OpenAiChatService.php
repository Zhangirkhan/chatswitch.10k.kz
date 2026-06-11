<?php

declare(strict_types=1);

namespace App\Services\AI;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Тонкий клиент над OpenAI Chat Completions.
 * Возвращает текст или строго распарсенный JSON: кодом сети не должны заниматься
 * вызывающие сервисы.
 */
final class OpenAiChatService
{
    public function __construct(
        private readonly AiUsageRecorder $usageRecorder,
        private readonly OpenAiModelResolver $modelResolver,
    ) {}

    /**
     * @param  array<int, array{role: 'system'|'user'|'assistant', content: string}>  $messages
     */
    public function chat(
        array $messages,
        ?float $temperature = null,
        ?int $maxTokens = null,
        ?AiUsageOptions $usage = null,
    ): string {
        $result = $this->send($messages, $temperature, $maxTokens, null, $usage);
        $content = $result['message']['content'] ?? null;

        if (! is_string($content) || trim($content) === '') {
            throw new RuntimeException('Пустой ответ от OpenAI.');
        }

        return $content;
    }

    /**
     * Like chat() but also returns token usage for populating AiResponseLog.
     *
     * @param  array<int, array{role: 'system'|'user'|'assistant', content: string}>  $messages
     * @return array{content: string, tokens_prompt: int, tokens_completion: int}
     */
    public function chatWithUsage(
        array $messages,
        ?float $temperature = null,
        ?int $maxTokens = null,
        ?AiUsageOptions $usage = null,
    ): array {
        $result = $this->send($messages, $temperature, $maxTokens, null, $usage);
        $content = $result['message']['content'] ?? null;

        if (! is_string($content) || trim($content) === '') {
            throw new RuntimeException('Пустой ответ от OpenAI.');
        }

        return [
            'content'           => $content,
            'tokens_prompt'     => $result['tokens_prompt'] ?? 0,
            'tokens_completion' => $result['tokens_completion'] ?? 0,
        ];
    }

    /**
     * @param  array<int, array{role: 'system'|'user'|'assistant', content: string}>  $messages
     * @return array<string, mixed>
     */
    public function chatJson(
        array $messages,
        ?float $temperature = null,
        ?int $maxTokens = null,
        ?AiUsageOptions $usage = null,
    ): array {
        $result = $this->send($messages, $temperature, $maxTokens, ['type' => 'json_object'], $usage);
        $content = $result['message']['content'] ?? null;

        if (! is_string($content) || trim($content) === '') {
            throw new RuntimeException('Пустой JSON-ответ от OpenAI.');
        }

        $decoded = json_decode($content, true);
        if (! is_array($decoded)) {
            throw new RuntimeException('OpenAI вернул невалидный JSON.');
        }

        return $decoded;
    }

    /**
     * @param  array<int, array{role: 'system'|'user'|'assistant', content: string}>  $messages
     * @param  array<string, mixed>|null  $responseFormat
     * @return array{message: array<string, mixed>, model: string, tokens_prompt: int, tokens_completion: int}
     */
    private function send(
        array $messages,
        ?float $temperature = null,
        ?int $maxTokens = null,
        ?array $responseFormat = null,
        ?AiUsageOptions $usage = null,
    ): array {
        $apiKey = (string) config('services.openai.api_key');
        $baseUrl = rtrim((string) config('services.openai.base_url', 'https://api.openai.com/v1'), '/');
        $companyId = $usage?->companyId;
        $scenario = $usage?->scenario;
        $model = $this->modelResolver->chatModel($companyId, $scenario);
        $timeout = $this->modelResolver->requestTimeout($companyId);
        $effectiveMaxTokens = $this->modelResolver->maxTokens($companyId, $maxTokens);

        if ($apiKey === '') {
            throw new RuntimeException('OPENAI_API_KEY не задан в .env (services.openai.api_key).');
        }

        try {
            $payload = [
                'model' => $model,
                'messages' => $messages,
            ];
            if ($this->modelResolver->supportsCustomTemperature($companyId)) {
                $payload['temperature'] = $temperature ?? 0.4;
            }
            if ($this->modelResolver->usesMaxCompletionTokens($companyId)) {
                $payload['max_completion_tokens'] = $effectiveMaxTokens;
            } else {
                $payload['max_tokens'] = $effectiveMaxTokens;
            }
            if ($responseFormat !== null) {
                $payload['response_format'] = $responseFormat;
            }

            $retryStatuses = array_map(
                'intval',
                explode(',', (string) config('ai.retry_on_http_statuses', '429,500,502,503,504')),
            );
            $baseBackoffMs = max(100, (int) config('ai.retry_base_backoff_ms', 1000));

            $response = Http::baseUrl($baseUrl)
                ->withToken($apiKey)
                ->acceptJson()
                ->asJson()
                ->timeout($timeout)
                ->retry(3, $baseBackoffMs, function (\Throwable $exception, \Illuminate\Http\Client\PendingRequest $request) use ($retryStatuses): bool {
                    // Always retry on connection errors (network timeout, DNS, etc.).
                    if ($exception instanceof ConnectionException) {
                        return true;
                    }

                    if (! $exception instanceof RequestException) {
                        return false;
                    }

                    return in_array($exception->response?->status(), $retryStatuses, true);
                }, throw: false)
                ->post('/chat/completions', $payload);

            if ($response->failed()) {
                $response->throw();
            }
        } catch (ConnectionException $e) {
            throw new RuntimeException('Не удалось подключиться к OpenAI: '.$e->getMessage(), 0, $e);
        } catch (RequestException $e) {
            $body = (string) ($e->response?->body() ?? '');
            throw new RuntimeException('OpenAI вернул ошибку: '.mb_substr($body, 0, 500), 0, $e);
        }

        $data = $response->json();
        $message = $data['choices'][0]['message'] ?? null;
        if (! is_array($message)) {
            throw new RuntimeException('OpenAI вернул ответ без message.');
        }

        if ($usage !== null) {
            $usageData = is_array($data['usage'] ?? null) ? $data['usage'] : [];
            $this->usageRecorder->recordChat(
                $usage->scenario,
                $usage->companyId,
                (string) ($data['model'] ?? $model),
                (int) ($usageData['prompt_tokens'] ?? 0),
                (int) ($usageData['completion_tokens'] ?? 0),
            );
        }

        $usageData = is_array($data['usage'] ?? null) ? $data['usage'] : [];

        return [
            'message'            => $message,
            'model'              => (string) ($data['model'] ?? $model),
            'tokens_prompt'      => (int) ($usageData['prompt_tokens'] ?? 0),
            'tokens_completion'  => (int) ($usageData['completion_tokens'] ?? 0),
        ];
    }
}
