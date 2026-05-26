<?php

declare(strict_types=1);

namespace App\Services\AI\Locale;

use Illuminate\Support\Facades\Cache;
use RuntimeException;

final class LocaleLexiconLoader
{
    /**
     * @return array<string, mixed>
     */
    public function load(string $name): array
    {
        $base = (string) (config('locale_assistant.lexicon_path') ?: resource_path('locale/lexicons'));
        $path = rtrim($base, '/').'/'.$name.'.json';
        $cacheKey = 'locale_lexicon:'.md5($path);

        return Cache::remember($cacheKey, 3600, function () use ($path): array {
            if (! is_readable($path)) {
                throw new RuntimeException("Locale lexicon not found: {$path}");
            }

            $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

            return is_array($decoded) ? $decoded : [];
        });
    }

    /**
     * @return list<string>
     */
    public function words(string $name, string $key = 'words'): array
    {
        $data = $this->load($name);
        $items = $data[$key] ?? $data['terms'] ?? [];

        if (! is_array($items)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $item): string => is_string($item) ? mb_strtolower(trim($item)) : '',
            $items,
        ), static fn (string $item): bool => $item !== ''));
    }
}
