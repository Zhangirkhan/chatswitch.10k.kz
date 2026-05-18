<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Funnel\FunnelStageFollowUpService;
use Illuminate\Console\Command;

final class ScheduleFunnelFollowUps extends Command
{
    protected $signature = 'funnel-follow-ups:schedule {--limit=80}';

    protected $description = 'Schedule automatic funnel stage follow-up messages for silent client chats.';

    public function handle(FunnelStageFollowUpService $service): int
    {
        $limit = max(1, min(300, (int) $this->option('limit')));
        $created = $service->scheduleDue($limit);
        $this->info("Funnel follow-ups scheduled: {$created}");

        return self::SUCCESS;
    }
}
