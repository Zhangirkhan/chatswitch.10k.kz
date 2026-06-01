<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\SlaReminderService;
use App\Support\SlaReminderSettings;
use App\Support\TenantConsole;
use Illuminate\Console\Command;

final class SendSlaReminders extends Command
{
    protected $signature = 'chats:sla-reminders
        {--minutes= : Override wait time (default from system settings)}
        {--dry-run : Only print how many reminders would be created}';

    protected $description = 'Create internal SLA reminders for chats where clients are waiting for a reply.';

    public function handle(
        SlaReminderService $service,
        SlaReminderSettings $settings,
        TenantConsole $tenantConsole,
    ): int {
        $minutesOption = $this->option('minutes');
        $minutes = $minutesOption !== null && $minutesOption !== ''
            ? max(SlaReminderSettings::MIN_MINUTES, (int) $minutesOption)
            : null;

        if ($this->option('dry-run')) {
            $total = 0;
            $tenantConsole->eachActiveCompany(function ($company) use ($service, $settings, $minutes, &$total): void {
                if (! $settings->enabled((int) $company->id)) {
                    return;
                }

                $total += $service->countEligible($minutes, (int) $company->id);
            });
            $this->info("Would create {$total} SLA reminder(s).");

            return self::SUCCESS;
        }

        $count = 0;
        $tenantConsole->eachActiveCompany(function ($company) use ($service, $settings, $minutes, &$count): void {
            if (! $settings->enabled((int) $company->id)) {
                return;
            }

            $count += $service->sendReminders($minutes, (int) $company->id);
        });

        $this->info("Created {$count} SLA reminder(s).");

        return self::SUCCESS;
    }
}
