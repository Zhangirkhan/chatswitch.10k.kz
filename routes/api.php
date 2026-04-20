<?php

declare(strict_types=1);

use App\Http\Controllers\Api\WhatsappWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('/whatsapp/webhook', [WhatsappWebhookController::class, 'handle'])
    ->name('api.whatsapp.webhook');
