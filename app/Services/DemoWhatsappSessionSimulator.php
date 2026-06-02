<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\WhatsappSession;
use App\Tenancy\TenantContext;

/**
 * В демо-тенанте WhatsApp-подключения имитируются как «Подключено» без QR и без
 * реального whatsapp-service — для презентации и обучения.
 */
final class DemoWhatsappSessionSimulator
{
    public function isDemoTenant(): bool
    {
        $slug = app(TenantContext::class)->slug();

        return $slug === (string) config('tenancy.fallback_slug', 'demo');
    }

    public function markConnected(WhatsappSession $session): WhatsappSession
    {
        $updates = [];

        if ($session->status !== 'connected') {
            $updates['status'] = 'connected';
        }

        if ($session->connected_at === null) {
            $updates['connected_at'] = now();
        }

        if (trim((string) $session->wa_name) === '' && trim((string) $session->display_name) !== '') {
            $updates['wa_name'] = $session->display_name;
        }

        if ($updates !== []) {
            $session->forceFill($updates)->save();
        }

        return $session->fresh();
    }

    /**
     * @return array<string, mixed>
     */
    public function simulatedStatusPayload(WhatsappSession $session): array
    {
        $session = $this->markConnected($session);

        return [
            'success' => true,
            'isReady' => true,
            'hasQR' => false,
            'isInitializing' => false,
            'session' => $session,
        ];
    }
}
