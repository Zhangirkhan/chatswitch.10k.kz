<?php

declare(strict_types=1);

namespace Tests\Unit\AI;

use App\Services\AI\PromptCompressionCache;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

final class PromptCompressionCacheTest extends TestCase
{
    public function test_remember_returns_cached_value_without_second_resolver_call(): void
    {
        Cache::flush();

        $cache = new PromptCompressionCache;
        $calls = 0;

        $first = $cache->remember('conversation', 'chat:1:msg:2:abc', function () use (&$calls): string {
            $calls++;

            return 'summary-one';
        });

        $second = $cache->remember('conversation', 'chat:1:msg:2:abc', function () use (&$calls): string {
            $calls++;

            return 'summary-two';
        });

        $this->assertSame('summary-one', $first);
        $this->assertSame('summary-one', $second);
        $this->assertSame(1, $calls);
    }

    public function test_different_fingerprint_misses_cache(): void
    {
        Cache::flush();

        $cache = new PromptCompressionCache;

        $this->assertSame('a', $cache->remember('conversation', 'fp-a', fn (): string => 'a'));
        $this->assertSame('b', $cache->remember('conversation', 'fp-b', fn (): string => 'b'));
    }
}
