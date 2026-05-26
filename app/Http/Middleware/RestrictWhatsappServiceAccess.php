<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ограничивает /api/whatsapp/* только доверенными IP (Node-сервис на localhost).
 */
final class RestrictWhatsappServiceAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $allowed = config('accel.whatsapp_service_ips', ['127.0.0.1', '::1']);

        if (! in_array($request->ip(), $allowed, true)) {
            abort(403, 'WhatsApp service access denied.');
        }

        return $next($request);
    }
}
