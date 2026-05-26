<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Tenancy\TenantNginxMapService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

final class IssueTenantCertificateJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 180;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(public readonly string $slug)
    {
        $this->onQueue('provisioning');
    }

    public function handle(): void
    {
        $script = base_path('deploy/nginx/issue-tenant-cert.sh');

        if (! is_file($script)) {
            Log::error('issue-tenant-cert script not found', ['script' => $script]);

            return;
        }

        $process = new Process(['sudo', '-n', $script, $this->slug]);
        $process->setTimeout($this->timeout);
        $process->run();

        $context = [
            'slug' => $this->slug,
            'exit_code' => $process->getExitCode(),
            'stderr' => trim((string) $process->getErrorOutput()),
            'stdout' => trim((string) $process->getOutput()),
        ];

        if (! $process->isSuccessful()) {
            Log::error('Failed to issue tenant certificate', $context);
            $this->fail(new \RuntimeException('certbot failed: '.$context['stderr']));

            return;
        }

        Log::info('Issued tenant certificate', $context);

        try {
            app(TenantNginxMapService::class)->writeMapFile();
            $reload = base_path('deploy/nginx/reload-nginx.sh');
            if (is_file($reload)) {
                $reloadProcess = new Process(['sudo', '-n', $reload]);
                $reloadProcess->setTimeout(15);
                $reloadProcess->run();
            }
        } catch (\Throwable $e) {
            Log::warning('tenants nginx map sync after cert failed', ['error' => $e->getMessage()]);
        }
    }
}
