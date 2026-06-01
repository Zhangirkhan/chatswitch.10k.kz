<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Support\Facades\Log;

final class SafeBroadcast
{
    public static function dispatch(ShouldBroadcast $event, string $context = 'broadcast'): void
    {
        try {
            broadcast($event);
        } catch (\Throwable $e) {
            Log::warning("[{$context}] broadcast failed", [
                'event' => $event::class,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
