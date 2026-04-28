<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class VerifyWhatsappWebhook
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('services.whatsapp.webhook_secret', '');

        if ($secret === '') {
            abort(500, 'WhatsApp webhook secret is not configured.');
        }

        $signature = (string) $request->header('X-Webhook-Signature', '');

        if ($signature === '') {
            abort(401, 'Missing webhook signature.');
        }

        $body = $request->getContent();
        $expected = hash_hmac('sha256', $body, $secret);

        if (! hash_equals($expected, $signature)) {
            abort(401, 'Invalid webhook signature.');
        }

        return $next($request);
    }
}
