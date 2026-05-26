<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Billing\SubscriptionLifecycleService;
use Illuminate\Console\Command;

final class ExpireSubscriptionTrialsCommand extends Command
{
    protected $signature = 'subscriptions:expire-trials';

    protected $description = 'Переводит компании с истёкшим триалом в статус past_due';

    public function handle(SubscriptionLifecycleService $lifecycle): int
    {
        $count = $lifecycle->expireEndedTrials();

        $this->info("Обработано компаний с истёкшим триалом: {$count}");

        return self::SUCCESS;
    }
}
