<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\WhatsappSession;
use Illuminate\Support\Facades\Log;

final class WhatsappSessionHealService
{
    public function __construct(
        private readonly WhatsappService $whatsappService,
    ) {}

    /**
     * Поднимает одну сессию, если она должна быть активной, но мертва в Node.
     *
     * @return 'healed'|'skipped_alive'|'skipped_initializing'|'skipped_qr'|'skipped_logged_out'|'skipped_unreachable'
     */
    public function healSession(WhatsappSession $session): string
    {
        if ($session->desired_state === WhatsappSession::DESIRED_LOGGED_OUT) {
            return 'skipped_logged_out';
        }

        if (! $this->whatsappService->healthReachable()) {
            return 'skipped_unreachable';
        }

        $verify = $this->whatsappService->verifySession($session->session_name);

        $alive = (bool) ($verify['alive'] ?? false);
        $isInitializing = (bool) ($verify['isInitializing'] ?? false);
        $hasQr = (bool) ($verify['hasQR'] ?? false);

        if ($alive || $isInitializing) {
            return 'skipped_alive';
        }

        if ($hasQr) {
            return 'skipped_qr';
        }

        try {
            $this->whatsappService->initializeSession($session->session_name, (int) $session->company_id);
            $session->forceFill(['status' => 'connecting'])->save();

            return 'healed';
        } catch (\Throwable $e) {
            Log::error('whatsapp session heal initialize failed', [
                'session' => $session->session_name,
                'company_id' => $session->company_id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
