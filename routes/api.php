<?php

declare(strict_types=1);

use App\Http\Controllers\Api\WhatsappWebhookController;
use App\Models\WhatsappSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/whatsapp/webhook', [WhatsappWebhookController::class, 'handle'])
    ->middleware(['whatsapp.webhook', 'throttle:240,1'])
    ->name('api.whatsapp.webhook');

// Node-сервис при старте сверяется с этим списком, чтобы не поднимать «фантомные»
// сессии, которых уже нет в БД (и не расходовать Chromium впустую).
Route::get('/whatsapp/legal-sessions', function (Request $request) {
    $expected = (string) config('services.whatsapp.service_token', '');
    $provided = (string) $request->bearerToken();

    if ($expected === '' || ! hash_equals($expected, $provided)) {
        abort(401);
    }

    return response()->json([
        'sessions' => WhatsappSession::pluck('session_name')->values(),
    ]);
})->middleware('throttle:60,1');
