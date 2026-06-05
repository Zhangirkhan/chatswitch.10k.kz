<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\WhatsappSession;
use App\Services\WhatsappSessionHealService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class ReinitializeWhatsappSessionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    /** @var list<int> */
    public array $backoff = [5, 15];

    public function __construct(public readonly int $whatsappSessionId) {}

    public function viaQueue(): string
    {
        return 'whatsapp';
    }

    public function handle(WhatsappSessionHealService $healService): void
    {
        $session = WhatsappSession::query()
            ->withoutGlobalScope('tenant')
            ->find($this->whatsappSessionId);

        if ($session === null) {
            return;
        }

        $healService->healSession($session);
    }
}
