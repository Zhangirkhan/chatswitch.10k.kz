<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

final class MonitorFailedQueueJobsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_logs_warning_when_critical_job_failed_recently(): void
    {
        Log::spy();

        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(),
            'connection' => 'database',
            'queue' => 'default',
            'payload' => '{"displayName":"App\\\\Jobs\\\\GenerateAiReplyJob"}',
            'exception' => 'RuntimeException: test failure',
            'failed_at' => now(),
        ]);

        $this->artisan('queue:monitor-failed', ['--hours' => 1, '--max' => 99])
            ->assertSuccessful();

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(function (string $message, array $context): bool {
                return $message === '[queue-monitor] elevated failed job count'
                    && ($context['critical_count'] ?? 0) >= 1;
            });
    }
}
