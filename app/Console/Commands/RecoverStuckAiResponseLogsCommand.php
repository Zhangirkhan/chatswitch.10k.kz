<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\GenerateAiReplyJob;
use App\Models\AiResponseLog;
use App\Models\Message;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Recovers AiResponseLog records stuck in the 'generating' state.
 *
 * A record is considered stuck when it has been in 'generating' status for
 * longer than the configurable timeout (default: 15 minutes). This happens
 * when a queue worker is killed mid-job (OOM, restart, deploy) without
 * invoking the job's failed() handler.
 *
 * Recovery steps:
 *  1. Mark stuck records as 'failed'.
 *  2. For each, check if the trigger message is still the latest inbound.
 *     If yes — re-dispatch GenerateAiReplyJob so the AI still replies.
 *     If not — leave as failed (a newer message already got/will get a reply).
 *
 * Schedule: run every 5 minutes via the scheduler.
 */
final class RecoverStuckAiResponseLogsCommand extends Command
{
    protected $signature = 'ai:recover-stuck-logs
        {--timeout=15 : Minutes after which a generating record is considered stuck}
        {--dry-run : List stuck records without modifying anything}';

    protected $description = 'Mark stuck ai_response_logs (generating → failed) and re-dispatch where applicable';

    public function handle(): int
    {
        $timeout = max(1, (int) $this->option('timeout'));
        $dryRun = (bool) $this->option('dry-run');
        $cutoff = now()->subMinutes($timeout);

        $stuck = AiResponseLog::query()
            ->where('status', 'generating')
            ->where('updated_at', '<=', $cutoff)
            ->get();

        if ($stuck->isEmpty()) {
            $this->info('No stuck ai_response_logs found.');

            return self::SUCCESS;
        }

        $this->info("Found {$stuck->count()} stuck record(s) (generating > {$timeout} min).");

        foreach ($stuck as $log) {
            $this->line("  → log #{$log->id}, chat #{$log->chat_id}, trigger #{$log->trigger_message_id}");

            if ($dryRun) {
                continue;
            }

            // Mark as failed.
            $log->forceFill([
                'status' => 'failed',
                'error' => "Recovered by ai:recover-stuck-logs after >{$timeout} min in generating state.",
            ])->save();

            Log::info('[ai-recover] stuck log recovered', [
                'log_id' => $log->id,
                'chat_id' => $log->chat_id,
                'trigger_message_id' => $log->trigger_message_id,
            ]);

            // Re-dispatch only if the trigger is still the latest inbound.
            if ($log->trigger_message_id && $log->chat_id) {
                $this->maybeRedispatch($log);
            }
        }

        $this->info($dryRun ? 'Dry run — no changes made.' : "Recovered {$stuck->count()} log(s).");

        return self::SUCCESS;
    }

    private function maybeRedispatch(AiResponseLog $log): void
    {
        $latestInboundId = Message::query()
            ->where('chat_id', $log->chat_id)
            ->where('direction', 'inbound')
            ->latest('message_timestamp')
            ->latest('id')
            ->value('id');

        if ((int) $latestInboundId !== (int) $log->trigger_message_id) {
            // A newer inbound arrived — do not re-dispatch to avoid sending an
            // out-of-order reply. The new message's own job should handle it.
            Log::info('[ai-recover] skipped redispatch — newer inbound exists', [
                'log_id' => $log->id,
                'trigger_message_id' => $log->trigger_message_id,
                'latest_inbound_id' => $latestInboundId,
            ]);

            return;
        }

        GenerateAiReplyJob::dispatch(
            $log->chat_id,
            $log->trigger_message_id,
            $log->company_id,
        );

        Log::info('[ai-recover] re-dispatched GenerateAiReplyJob', [
            'log_id' => $log->id,
            'chat_id' => $log->chat_id,
            'trigger_message_id' => $log->trigger_message_id,
        ]);
    }
}
