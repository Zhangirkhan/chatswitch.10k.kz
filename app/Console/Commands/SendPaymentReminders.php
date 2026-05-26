<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Billing\PaymentReminderService;
use Illuminate\Console\Command;

final class SendPaymentReminders extends Command
{
    protected $signature = 'billing:send-payment-reminders
        {--date= : Дата «сегодня» (Y-m-d) для теста}
        {--dry-run : Только показать, сколько писем ушло бы}';

    protected $description = 'Отправить клиентам email-напоминания об оплате до окончания триала или периода подписки.';

    public function handle(PaymentReminderService $reminders): int
    {
        if (! $reminders->enabled()) {
            $this->info('Напоминания об оплате отключены в config/billing.php.');

            return self::SUCCESS;
        }

        $days = implode(', ', array_map(strval(...), $reminders->daysBeforeOptions()));
        $this->line('Интервалы напоминаний (дней до платежа): '.$days);

        if ($this->option('dry-run')) {
            $this->warn('Режим dry-run: письма не отправляются. Запустите без --dry-run.');

            return self::SUCCESS;
        }

        $date = $this->option('date');
        $onDate = is_string($date) && $date !== '' ? now()->parse($date) : null;

        $result = $reminders->sendDueReminders($onDate);

        $this->info(sprintf(
            'Готово: отправлено %d, пропущено %d, ошибок %d.',
            $result['sent'],
            $result['skipped'],
            $result['failed'],
        ));

        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
