<?php

declare(strict_types=1);

namespace App\Services\AI;

use Illuminate\Support\Facades\Http;
use RuntimeException;

final class OpenAiEmbeddingService
{
    public function __construct(
        private readonly AiUsageRecorder $usageRecorder,
    ) {}

    /**
     * @return list<float>
     */
    public function embed(string $text, ?AiUsageOptions $usage = null): array
    {
        $vectors = $this->embedMany([$text], $usage);

        return $vectors[0] ?? [];
    }

    /**
     * @param  list<string>  $texts
     * @return list<list<float>>
     */
    public function embedMany(array $texts, ?AiUsageOptions $usage = null): array
    {
        $inputs = array_values(array_filter(array_map(
            static fn (string $text): string => trim($text),
            $texts,
        ), static fn (string $text): bool => $text !== ''));

        if ($inputs === []) {
            return [];
        }

        $apiKey = (string) config('services.openai.api_key');
        $baseUrl = rtrim((string) config('services.openai.base_url', 'https://api.openai.com/v1'), '/');
        $model = (string) config('knowledge.rag.embedding_model', 'text-embedding-3-small');
        $timeout = (int) config('services.openai.timeout', 45);

        if ($apiKey === '') {
            throw new RuntimeException('OPENAI_API_KEY не задан в .env (services.openai.api_key).');
        }

        $response = Http::withToken($apiKey)
            ->timeout($timeout)
            ->acceptJson()
            ->retry(3, 1000, function (\Throwable $exception): bool {
                if (! $exception instanceof \Illuminate\Http\Client\RequestException) {
                    return false;
                }

                return $exception->response?->status() === 429;
            })
            ->post("{$baseUrl}/embeddings", [
                'model' => $model,
                'input' => $inputs,
            ])
            ->throw()
            ->json();

        if ($usage !== null) {
            $usageData = is_array($response['usage'] ?? null) ? $response['usage'] : [];
            $this->usageRecorder->recordEmbedding(
                $usage->scenario,
                $usage->companyId,
                (string) ($response['model'] ?? $model),
                (int) ($usageData['total_tokens'] ?? 0),
            );
        }

        $data = $response['data'] ?? null;
        if (! is_array($data)) {
            throw new RuntimeException('Некорректный ответ embeddings API.');
        }

        usort($data, static fn (array $a, array $b): int => ((int) ($a['index'] ?? 0)) <=> ((int) ($b['index'] ?? 0)));

        $vectors = [];
        foreach ($data as $row) {
            $vector = $row['embedding'] ?? null;
            if (! is_array($vector)) {
                throw new RuntimeException('Embeddings API вернул пустой вектор.');
            }
            $vectors[] = array_map(static fn ($value): float => (float) $value, $vector);
        }

        return $vectors;
    }
}
