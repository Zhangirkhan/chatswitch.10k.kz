<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Memory\EntityMemoryService;
use Illuminate\Console\Command;

final class SyncEntityMemoryFilesCommand extends Command
{
    protected $signature = 'entity-memory:sync-files';

    protected $description = 'Записать все memory.md из БД в storage (и проверить бэкапы на диске)';

    public function handle(EntityMemoryService $service): int
    {
        $count = $service->syncAllToFiles();
        $this->info("Синхронизировано записей: {$count}");

        return self::SUCCESS;
    }
}
