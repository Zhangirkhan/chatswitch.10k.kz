<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Tenancy\TenantNginxMapService;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

final class SyncTenantNginxMapCommand extends Command
{
    protected $signature = 'tenants:sync-nginx-map {--reload : nginx -t && reload после записи карты}';

    protected $description = 'Обновить nginx map известных тенантов (редирект неизвестных поддоменов на accel.kz)';

    public function handle(TenantNginxMapService $service): int
    {
        $count = $service->writeMapFile();
        $this->info("Записано {$count} хост(ов) в {$service->mapFilePath()}");

        if ($this->option('reload')) {
            $script = base_path('deploy/nginx/reload-nginx.sh');
            if (! is_file($script)) {
                $this->warn('Скрипт reload не найден, пропускаю reload.');

                return self::SUCCESS;
            }

            $process = Process::fromShellCommandline('sudo -n '.escapeshellarg($script));
            $process->setTimeout(30);
            $process->run();

            if (! $process->isSuccessful()) {
                $this->error(trim((string) $process->getErrorOutput()));

                return self::FAILURE;
            }

            $this->info('nginx перезагружен.');
        }

        return self::SUCCESS;
    }
}
