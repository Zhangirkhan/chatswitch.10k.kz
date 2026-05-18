<?php

declare(strict_types=1);

namespace App\Services\AI;

use Illuminate\Support\Facades\Http;
use RuntimeException;

final class OpenAiEmbeddingService
{
    /**
     * @return list<float>
     */
    public function embed(string $text): array
    {
        $vectors = $this->embedMany([$text]);

        return $vectors[0] ?? [];
    }

    /**
     * @param  list<string>  $texts
     * @return list<list<float>>
     */
    public function embedMany(array $texts): array
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
            ->post("{$baseUrl}/embeddings", [
                'model' => $model,
                'input' => $inputs,
            ])
            ->throw()
            ->json();

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
