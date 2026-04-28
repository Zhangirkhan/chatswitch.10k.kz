<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\WhatsappSession;
use App\Services\WhatsappService;
use Illuminate\Console\Command;

/**
 * Удаляет записи демо-сессий из сидера (demo-main, demo-sales, demo-support) и пытается убрать клиентов в whatsapp-service.
 */
final class PurgeDemoWhatsappSessions extends Command
{
    private const DEMO_NAMES = ['demo-main', 'demo-sales', 'demo-support'];

    protected $signature = 'whatsapp:purge-demo-sessions
                            {--force : Без интерактивного подтверждения}';

    protected $description = 'Удаляет демо-WhatsApp-сессии (demo-main / demo-sales / demo-support) из БД и destroy в микросервисе.';

    public function handle(WhatsappService $whatsappService): int
    {
        $count = WhatsappSession::whereIn('session_name', self::DEMO_NAMES)->count();
        if ($count === 0) {
            $this->info('Демо-сессий не найдено.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm("Удалить {$count} демо-сессий из БД и вызвать destroy в whatsapp-service?", false)) {
            $this->warn('Отменено.');

            return self::SUCCESS;
        }

        foreach (self::DEMO_NAMES as $name) {
            $session = WhatsappSession::where('session_name', $name)->first();
            if ($session === null) {
                continue;
            }
            if ($whatsappService->healthReachable()) {
                try {
                    $whatsappService->destroySession($name);
                } catch (\Throwable) {
                    // микросервис мог не знать сессию
                }
            }
            $session->delete();
            $this->line("Удалено: {$name}");
        }

        $this->info('Готово.');

        return self::SUCCESS;
    }
}
