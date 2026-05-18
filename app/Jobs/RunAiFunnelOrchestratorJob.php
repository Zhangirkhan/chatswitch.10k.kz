<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\AI\AiFunnelOrchestratorService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class RunAiFunnelOrchestratorJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct(
        public readonly int $chatId,
        public readonly int $triggerMessageId,
    ) {}

    public function handle(AiFunnelOrchestratorService $orchestrator): void
    {
        $orchestrator->run($this->chatId, $this->triggerMessageId);
    }

    public function viaQueue(): string
    {
        return 'ai';
    }
}
