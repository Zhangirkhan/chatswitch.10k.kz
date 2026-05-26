<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\ChatController;
use App\Http\Controllers\Api\V1\DepartmentController;
use Illuminate\Support\Facades\Route;

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
