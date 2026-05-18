<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\SlaReminderService;
use App\Support\SlaReminderSettings;
use Illuminate\Console\Command;

final class SendSlaReminders extends Command
{
    protected $signature = 'chats:sla-reminders
        {--minutes= : Override wait time (default from system settings)}
        {--dry-run : Only print how many reminders would be created}';

    protected $description = 'Create internal SLA reminders for chats where clients are waiting for a reply.';

    public function handle(SlaReminderService $service, SlaReminderSettings $settings): int
    {
        if (! $settings->enabled()) {
            $this->info('SLA reminders are disabled in system settings.');

            return self::SUCCESS;
        }

        $minutesOption = $this->option('minutes');
        $minutes = $minutesOption !== null && $minutesOption !== ''
            ? max(SlaReminderSettings::MIN_MINUTES, (int) $minutesOption)
            : null;

        $resolved = $minutes ?? $settings->waitMinutes();

        if ($this->option('dry-run')) {
            $count = $service->countEligible($minutes);
            $this->info("Would create {$count} SLA reminder(s) for chats waiting {$resolved}+ minutes.");

            return self::SUCCESS;
        }

        $count = $service->sendReminders($minutes);
        $this->info("Created {$count} SLA reminder(s) for chats waiting {$resolved}+ minutes.");

        return self::SUCCESS;
    }
}
