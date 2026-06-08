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

        if ($alive) {
            $this->syncAliveSessionIfStale($session, $verify);

            return 'skipped_alive';
        }

        if ($isInitializing) {
            return 'skipped_initializing';
        }

        if ($hasQr) {
            return 'skipped_qr';
        }

        $needsHardReset = $this->needsHardReset($verify);

        try {
            if ($needsHardReset) {
                $this->whatsappService->destroySession($session->session_name);
            }

            $this->whatsappService->initializeSession($session->session_name, (int) $session->company_id);
            $session->forceFill(['status' => 'connecting'])->save();

            Log::info('whatsapp session heal triggered initialize', [
                'session' => $session->session_name,
                'company_id' => $session->company_id,
                'hard_reset' => $needsHardReset,
                'reasoning' => $verify['reasoning'] ?? null,
            ]);

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

    /**
     * @param  array<string, mixed>  $verify
     */
    private function needsHardReset(array $verify): bool
    {
        $browserConnected = (bool) ($verify['browserConnected'] ?? false);
        $isReady = (bool) ($verify['isReady'] ?? false);
        $lastError = strtolower((string) ($verify['lastError'] ?? ''));
        $reasoning = is_array($verify['reasoning'] ?? null) ? $verify['reasoning'] : [];

        if ($isReady && ! $browserConnected) {
            return true;
        }

        if (in_array('browser_disconnected', $reasoning, true)) {
            return true;
        }

        if (str_contains($lastError, 'detached') || str_contains($lastError, 'target closed')) {
            return true;
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $verify
     */
    private function syncAliveSessionIfStale(WhatsappSession $session, array $verify): void
    {
        if (($session->status ?? '') === 'connected') {
            return;
        }

        $session->forceFill(['status' => 'connected'])->save();

        try {
            $this->whatsappService->syncInboundMessages($session->session_name);
        } catch (\Throwable $e) {
            Log::warning('whatsapp session inbound sync after alive verify failed', [
                'session' => $session->session_name,
                'error' => $e->getMessage(),
                'verify' => $verify,
            ]);
        }
    }
}
