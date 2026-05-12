<?php

declare(strict_types=1);

namespace App\Services\AI;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Тонкий клиент над OpenAI Chat Completions.
 * Возвращает только текст ответа: парсингом JSON и кодом сети не должны заниматься
 * вызывающие сервисы (DRY и тестируемость).
 */
final class OpenAiChatService
{
    /**
     * @param  array<int, array{role: 'system'|'user'|'assistant', content: string}>  $messages
     */
    public function chat(array $messages, ?float $temperature = null, ?int $maxTokens = null): string
    {
        $apiKey = (string) config('services.openai.api_key');
        $baseUrl = rtrim((string) config('services.openai.base_url', 'https://api.openai.com/v1'), '/');
        $model = (string) config('services.openai.model', 'gpt-4o-mini');
        $timeout = (int) config('services.openai.timeout', 45);

        if ($apiKey === '') {
            throw new RuntimeException('OPENAI_API_KEY не задан в .env (services.openai.api_key).');
        }

        try {
            $response = Http::baseUrl($baseUrl)
                ->withToken($apiKey)
                ->acceptJson()
                ->asJson()
                ->timeout($timeout)
                ->post('/chat/completions', [
                    'model' => $model,
                    'messages' => $messages,
                    'temperature' => $temperature ?? 0.4,
                    'max_tokens' => $maxTokens ?? 900,
                ])
                ->throw();
        } catch (ConnectionException $e) {
            throw new RuntimeException('Не удалось подключиться к OpenAI: '.$e->getMessage(), 0, $e);
        } catch (RequestException $e) {
            $body = (string) ($e->response?->body() ?? '');
            throw new RuntimeException('OpenAI вернул ошибку: '.mb_substr($body, 0, 500), 0, $e);
        }

        $data = $response->json();
        $content = $data['choices'][0]['message']['content'] ?? null;

        if (! is_string($content) || trim($content) === '') {
            throw new RuntimeException('Пустой ответ от OpenAI.');
        }

        return $content;
    }
}
