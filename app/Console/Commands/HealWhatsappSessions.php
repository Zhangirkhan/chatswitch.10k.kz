<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\WhatsappSession;
use App\Services\WhatsappService;
use App\Services\WhatsappSessionHealService;
use App\Services\Whatsapp\WhatsappSessionHealthMonitorService;
use Illuminate\Console\Command;

/**
 * Watchdog, который держит сессии «всегда живыми» до тех пор, пока пользователь
 * сам не нажал «Выйти» (в этом случае у сессии desired_state = 'logged_out').
 *
 * Логика прохода: для каждой сессии с desired_state=active спрашиваем
 * whatsapp-service /verify. Если подключение мёртвое и попыток инициализации
 * сейчас нет — вызываем /initialize, чтобы Node поднял клиента заново.
 *
 * Команда безопасно идемпотентна: если клиент уже инициализируется или alive,
 * ничего не делаем. Это покрывает сценарии:
 *   - Node был перезапущен (pm2 restart) и сессия не поднялась со старта;
 *   - Puppeteer упал и Node-локальный авто-реконнект не справился;
 *   - Laravel-рестарт оставил БД в старом статусе.
 */
final class HealWhatsappSessions extends Command
{
    protected $signature = 'whatsapp:heal
                            {--dry-run : Только показать, что будет сделано}';

    protected $description = 'Поднимает отвалившиеся WhatsApp-сессии, которые пользователь не выключал вручную.';

    public function handle(
        WhatsappService $whatsappService,
        WhatsappSessionHealService $healService,
        WhatsappSessionHealthMonitorService $monitorService,
    ): int {
        if (! $whatsappService->healthReachable()) {
            $this->warn('whatsapp-service недоступен — heal пропущен.');

            return self::SUCCESS;
        }

        $sessions = WhatsappSession::query()
            ->where('desired_state', WhatsappSession::DESIRED_ACTIVE)
            ->orderBy('id')
            ->get();

        if ($sessions->isEmpty()) {
            $this->info('Нет сессий с desired_state=active — heal пропущен.');

            return self::SUCCESS;
        }

        $healed = 0;
        $skipped = 0;
        $alerted = 0;

        foreach ($sessions as $session) {
            if ($this->option('dry-run')) {
                $verify = $whatsappService->verifySession($session->session_name);
                $reason = is_array($verify['reasoning'] ?? null)
                    ? implode(', ', $verify['reasoning'])
                    : 'unknown';
                $this->line(sprintf('[%s] dry-run check (%s)', $session->session_name, $reason));
                continue;
            }

            try {
                $result = $healService->healSession($session);
            } catch (\Throwable $e) {
                $this->error(sprintf('[%s] ошибка initialize: %s', $session->session_name, $e->getMessage()));
                $result = 'error';
            }

            $verify = $whatsappService->verifySession($session->session_name);
            $monitorResult = $monitorService->observe($session, $verify);
            if ($monitorResult === 'alert_sent') {
                $alerted++;
            }

            if ($result === 'healed') {
                $this->line(sprintf('[%s] переинициализировано', $session->session_name));
                $healed++;
                continue;
            }

            if ($result !== 'error') {
                $skipped++;
            }
        }

        $this->info(sprintf(
            'Готово: поднято %d, пропущено %d, алертов %d из %d.',
            $healed,
            $skipped,
            $alerted,
            $sessions->count(),
        ));

        return self::SUCCESS;
    }
}
