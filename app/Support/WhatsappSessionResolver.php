<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\WhatsappSession;
use Illuminate\Support\Facades\Log;

final class WhatsappSessionResolver
{
    public static function resolveByName(string $sessionName, ?int $companyId = null): ?WhatsappSession
    {
        $sessionName = trim($sessionName);
        if ($sessionName === '') {
            return null;
        }

        $query = WhatsappSession::query()
            ->withoutGlobalScope('tenant')
            ->where('session_name', $sessionName);

        if ($companyId !== null && $companyId > 0) {
            return $query->where('company_id', $companyId)->first();
        }

        $sessions = $query->get();
        if ($sessions->isEmpty()) {
            return null;
        }

        if ($sessions->count() > 1) {
            Log::error('[whatsapp] ambiguous session_name across tenants', [
                'session_name' => $sessionName,
                'company_ids' => $sessions->pluck('company_id')->all(),
            ]);

            return null;
        }

        return $sessions->first();
    }
}
