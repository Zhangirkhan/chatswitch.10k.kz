<?php

declare(strict_types=1);

namespace App\Services\AI;

use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * Кэширует результат сжатия длинного контекста для промпта (история чата, каталог).
 */
final class PromptCompressionCache
{
    public function remember(string $namespace, string $fingerprint, Closure $resolver): string
    {
        $key = 'ai_prompt_compress:'.preg_replace('/[^a-z0-9_-]/i', '_', $namespace).':'.hash('sha256', $fingerprint);
        $ttlDays = max(1, (int) config('ai.compression_cache_ttl_days', 7));

        /** @var string */
        return Cache::remember($key, now()->addDays($ttlDays), static function () use ($resolver): string {
            $result = $resolver();

            return is_string($result) ? $result : '';
        });
    }
}
