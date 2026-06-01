<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\ChatController;
use App\Http\Controllers\Api\V1\DepartmentController;
use App\Http\Controllers\Api\WhatsappWebhookController;
use App\Models\WhatsappSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

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
                ->get(['session_name', 'company_id'])
                ->map(static fn (WhatsappSession $session): array => [
                    'session_name' => $session->session_name,
                    'company_id' => $session->company_id,
                ])
                ->values(),
        ]);
    })->name('api.whatsapp.legal-sessions');
});

Route::prefix('v1')->middleware(['throttle:api'])->group(function (): void {
    Route::post('auth/login', [AuthController::class, 'login']);

    Route::middleware(['auth:sanctum', 'api.active', 'role:administrator,manager,employee'])->group(function (): void {
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::get('auth/me', [AuthController::class, 'me']);

        Route::get('departments', [DepartmentController::class, 'index']);

        Route::get('chats', [ChatController::class, 'index']);
        Route::get('chats/archived', [ChatController::class, 'archivedIndex']);
        Route::get('chats/{chat}', [ChatController::class, 'show']);
        Route::get('chats/{chat}/messages', [ChatController::class, 'messages']);
        Route::post('chats/{chat}/messages', [ChatController::class, 'storeMessage']);
        Route::post('chats/{chat}/read', [ChatController::class, 'markRead']);
        Route::post('chats/{chat}/typing', [ChatController::class, 'typing']);
    });
});
