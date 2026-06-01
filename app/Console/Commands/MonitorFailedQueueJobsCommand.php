<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class MonitorFailedQueueJobsCommand extends Command
{
    protected $signature = 'queue:monitor-failed
        {--hours=1 : Count failures newer than this many hours}
        {--max= : Recent failure threshold (default: config accel.queue_monitor.recent_max)}';

    protected $description = 'Log a warning when failed queue jobs exceed configured thresholds.';

    public function handle(): int
    {
        $hours = max(1, (int) $this->option('hours'));
        $since = now()->subHours($hours);
        $recentMax = $this->option('max') !== null
            ? max(0, (int) $this->option('max'))
            : max(0, (int) config('accel.queue_monitor.recent_max', 3));

        $total = (int) DB::table('failed_jobs')->count();
        $recent = DB::table('failed_jobs')
            ->where('failed_at', '>=', $since)
            ->orderByDesc('id')
            ->get(['id', 'queue', 'payload', 'failed_at', 'exception']);

        $recentCount = $recent->count();
        $critical = $recent->filter(fn (object $row): bool => $this->isCriticalPayload((string) $row->payload));

        $this->line("Failed jobs: total={$total}, last {$hours}h={$recentCount}, critical={$critical->count()}");

        if ($recentCount === 0) {
            return self::SUCCESS;
        }

        $samples = $recent->take(5)->map(function (object $row): array {
            return [
                'id' => $row->id,
                'queue' => $row->queue,
                'job' => $this->resolveJobClass((string) $row->payload),
                'failed_at' => $row->failed_at,
                'error' => mb_substr((string) $row->exception, 0, 240),
            ];
        })->all();

        $shouldWarn = $recentCount >= $recentMax || $critical->isNotEmpty();

        if ($shouldWarn) {
            Log::warning('[queue-monitor] elevated failed job count', [
                'total' => $total,
                'recent_count' => $recentCount,
                'recent_hours' => $hours,
                'critical_count' => $critical->count(),
                'samples' => $samples,
            ]);

            $this->warn('Threshold exceeded — details written to application log.');
        } else {
            Log::info('[queue-monitor] recent failures within threshold', [
                'total' => $total,
                'recent_count' => $recentCount,
                'recent_hours' => $hours,
            ]);
        }

        return self::SUCCESS;
    }

    private function isCriticalPayload(string $payload): bool
    {
        $needles = (array) config('accel.queue_monitor.critical_jobs', []);

        foreach ($needles as $needle) {
            if (is_string($needle) && $needle !== '' && str_contains($payload, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function resolveJobClass(string $payload): string
    {
        $decoded = json_decode($payload, true);
        $serialized = $decoded['data']['command'] ?? null;
        if (! is_string($serialized)) {
            return 'unknown';
        }

        try {
            $command = unserialize($serialized, ['allowed_classes' => true]);

            return is_object($command) ? $command::class : 'unknown';
        } catch (\Throwable) {
            return 'unknown';
        }
    }
}
