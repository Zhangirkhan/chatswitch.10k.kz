<?php

declare(strict_types=1);

namespace Tests\Unit\Broadcast;

use App\Services\Broadcast\BroadcastSendRateLimiter;
use Tests\TestCase;

final class BroadcastSendRateLimiterTest extends TestCase
{
    public function test_random_delay_stays_within_bounds(): void
    {
        $limiter = app(BroadcastSendRateLimiter::class);
        $min = $limiter->minDelayBetweenMessages();
        $max = $limiter->maxDelayBetweenMessages();

        for ($i = 0; $i < 50; $i++) {
            $delay = $limiter->randomDelayBetweenMessages();
            $this->assertGreaterThanOrEqual($min, $delay);
            $this->assertLessThanOrEqual($max, $delay);
        }
    }

    public function test_average_delay_fits_daily_quota(): void
    {
        $limiter = app(BroadcastSendRateLimiter::class);
        $average = $limiter->delayBetweenMessages();

        $this->assertSame(
            (int) round((86400 / BroadcastSendRateLimiter::MAX_MESSAGES_PER_DAY)),
            $average,
        );
    }
}
