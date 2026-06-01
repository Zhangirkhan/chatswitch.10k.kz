<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Funnel\ChatFunnelIntegrityService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

final class RepairCrossTenantChatFunnelsCommand extends Command
{
    protected $signature = 'chats:repair-funnel-integrity {--dry-run : List mismatched chats without fixing}';

    protected $description = 'Find and repair chats assigned to another tenant\'s funnel.';

    public function handle(ChatFunnelIntegrityService $integrity): int
    {
        $mismatched = $integrity->findMismatchedChats();

        if ($mismatched->isEmpty()) {
            $this->info('No cross-tenant funnel assignments found.');

            return self::SUCCESS;
        }

        $chatIds = $mismatched->pluck('id')->all();
        Log::warning('[funnel-integrity] cross-tenant funnel assignments detected', [
            'count' => $mismatched->count(),
            'chat_ids' => $chatIds,
            'dry_run' => (bool) $this->option('dry-run'),
        ]);

        $this->warn("Found {$mismatched->count()} chat(s) with funnel from another company:");

        foreach ($mismatched as $chat) {
            $this->line("  chat {$chat->id}: company {$chat->company_id}, funnel {$chat->funnel_id}");
        }

        if ($this->option('dry-run')) {
            $this->info('Dry run — no changes made.');

            return self::SUCCESS;
        }

        $result = $integrity->repairAll();
        $this->info("Repaired {$result['repaired']} of {$result['scanned']} chat(s).");

        return self::SUCCESS;
    }
}
