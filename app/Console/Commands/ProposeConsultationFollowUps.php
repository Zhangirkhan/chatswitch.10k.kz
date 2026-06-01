<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Funnel\ConsultationFollowUpProposalService;
use Illuminate\Console\Command;

final class ProposeConsultationFollowUps extends Command
{
    protected $signature = 'funnel-follow-ups:propose {--limit=40}';

    protected $description = 'Generate manager follow-up proposals for silent chats on funnel stages with manager_proposals strategy.';

    public function handle(ConsultationFollowUpProposalService $service): int
    {
        $limit = max(1, min(200, (int) $this->option('limit')));
        $created = $service->scheduleDue($limit);
        $this->info("Follow-up proposals created: {$created}");

        return self::SUCCESS;
    }
}
