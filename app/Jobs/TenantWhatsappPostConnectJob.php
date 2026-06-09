<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\WhatsappSession;
use App\Services\AI\AiReadinessService;
use App\Services\WhatsappService;
use App\Services\WhatsappSessionHealService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

final class TenantWhatsappPostConnectJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 30, 60];

    public function __construct(public readonly int $whatsappSessionId) {}

    public function viaQueue(): string
    {
        return 'whatsapp';
    }

    public function handle(
        WhatsappService $whatsapp,
        WhatsappSessionHealService $healService,
        AiReadinessService $readiness,
    ): void {
        $session = WhatsappSession::query()
            ->withoutGlobalScope('tenant')
            ->find($this->whatsappSessionId);

        if ($session === null) {
            return;
        }

        if ($session->desired_state !== WhatsappSession::DESIRED_ACTIVE) {
            return;
        }

        if (! $whatsapp->healthReachable()) {
            Log::warning('TenantWhatsappPostConnectJob: whatsapp-service unreachable', [
                'session' => $session->session_name,
                'company_id' => $session->company_id,
            ]);

            return;
        }

        $verify = $whatsapp->verifySession($session->session_name);
        $alive = (bool) ($verify['alive'] ?? false);

        if (! $alive) {
            try {
                $healService->healSession($session);
                $verify = $whatsapp->verifySession($session->session_name);
                $alive = (bool) ($verify['alive'] ?? false);
            } catch (\Throwable $e) {
                Log::error('TenantWhatsappPostConnectJob: heal failed', [
                    'session' => $session->session_name,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($alive) {
            $session->forceFill([
                'status' => 'connected',
                'connected_at' => $session->connected_at ?? now(),
                'qr_required_at' => null,
            ])->save();

            try {
                $whatsapp->syncInboundMessages($session->session_name);
            } catch (\Throwable $e) {
                Log::warning('TenantWhatsappPostConnectJob: sync inbound failed', [
                    'session' => $session->session_name,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $readiness->invalidateCounts((int) $session->company_id);
    }
}
