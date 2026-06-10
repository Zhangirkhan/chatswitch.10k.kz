<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\MobileUpdateCheckController;
use App\Http\Controllers\Api\WhatsappWebhookController;
use App\Models\WhatsappSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/v1/mobile/update-check', MobileUpdateCheckController::class)
    ->middleware('throttle:60,1')
    ->name('api.mobile.update-check');

Route::middleware(['whatsapp.service', 'throttle:whatsapp-service'])->group(function (): void {
    Route::post('/whatsapp/webhook', [WhatsappWebhookController::class, 'handle'])
        ->middleware(['whatsapp.webhook'])
        ->name('api.whatsapp.webhook');

    Route::post('/whatsapp/inbound-media', [WhatsappWebhookController::class, 'attachInboundMedia'])
        ->name('api.whatsapp.inbound-media');

    Route::get('/whatsapp/legal-sessions', function (Request $request) {
        $expected = (string) config('services.whatsapp.service_token', '');
        $provided = (string) $request->bearerToken();

        if ($expected === '' || ! hash_equals($expected, $provided)) {
            abort(401);
        }

        return response()->json([
            'sessions' => WhatsappSession::query()
                ->withoutGlobalScope('tenant')
                ->where('desired_state', WhatsappSession::DESIRED_ACTIVE)
                ->get(['session_name', 'company_id'])
                ->map(static fn (WhatsappSession $session): array => [
                    'session_name' => $session->session_name,
                    'company_id' => $session->company_id,
                ])
                ->values(),
        ]);
    })->name('api.whatsapp.legal-sessions');
});
