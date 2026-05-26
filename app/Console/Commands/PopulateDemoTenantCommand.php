<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\SuperAdmin\DemoTenantPopulationService;
use Illuminate\Console\Command;

final class PopulateDemoTenantCommand extends Command
{
    protected $signature = 'demo:populate {--force : На production без интерактивного подтверждения}';

    protected $description = 'Заполнить демо-тенант (slug demo) рабочими данными: воронка, отделы, чаты, каталог';

    public function handle(DemoTenantPopulationService $population): int
    {
        if (app()->environment('production') && ! $this->option('force')) {
            if (! $this->confirm('Пересоздать данные демо-тенанта? Старые чаты и настройки компании будут удалены.', false)) {
                $this->warn('Отменено.');

                return self::SUCCESS;
            }
        }

        $result = $population->populate();

        $this->info("Демо-тенант «{$result['company']->name}» заполнен.");
        $this->table(array_keys($result['stats']), [array_values($result['stats'])]);

        return self::SUCCESS;
    }
}
