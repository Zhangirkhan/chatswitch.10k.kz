<?php

declare(strict_types=1);

use App\Http\Controllers\ChatAssignmentController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\CommunityController;
use App\Http\Controllers\DepartmentController;
use App\Http\Controllers\MediaController;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\UserManagementController;
use App\Http\Controllers\WhatsappSessionController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', fn () => redirect()->route('login'));

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::get('/dashboard', fn () => redirect()->route('chats.index'));

    // Visual stubs (WhatsApp Web parity)
    Route::get('/status', fn () => Inertia::render('Status/Index'))->name('status.index');
    Route::get('/channels', fn () => Inertia::render('Channels/Index'))->name('channels.index');

    Route::middleware('role:administrator,manager,employee')->group(function (): void {
        Route::get('/chats', [ChatController::class, 'index'])->name('chats.index');
        Route::get('/chats/archived', [ChatController::class, 'archivedIndex'])->name('chats.archived');
        Route::get('/chats/contacts', [ChatController::class, 'contacts'])->name('chats.contacts');
        Route::post('/chats/start', [ChatController::class, 'start'])->name('chats.start');
        Route::post('/chats/create-group', [ChatController::class, 'createGroup'])->name('chats.create-group');
        Route::get('/chats/{chat}', [ChatController::class, 'show'])->name('chats.show');
        Route::post('/chats/{chat}/send-message', [ChatController::class, 'sendMessage'])->name('chats.send-message');
        Route::post('/chats/{chat}/typing', [ChatController::class, 'typing'])->name('chats.typing');
        Route::post('/chats/{chat}/mark-read', [ChatController::class, 'markRead'])->name('chats.mark-read');
        Route::post('/chats/{chat}/toggle-pin', [ChatController::class, 'togglePin'])->name('chats.toggle-pin');
        Route::post('/chats/{chat}/archive', [ChatController::class, 'archive'])->name('chats.archive');
        Route::post('/chats/{chat}/toggle-mute', [ChatController::class, 'toggleMute'])->name('chats.toggle-mute');
        Route::post('/chats/{chat}/toggle-favorite', [ChatController::class, 'toggleFavorite'])->name('chats.toggle-favorite');
        Route::post('/chats/{chat}/toggle-unread', [ChatController::class, 'toggleUnread'])->name('chats.toggle-unread');
        Route::post('/chats/{chat}/clear', [ChatController::class, 'clear'])->name('chats.clear');
        Route::post('/chats/{chat}/departments', [ChatController::class, 'syncDepartments'])->name('chats.departments.sync');
        Route::post('/chats/{chat}/upload-file', [ChatController::class, 'uploadFile'])->name('chats.upload-file');
        Route::post('/chats/{chat}/send-contact', [ChatController::class, 'sendContact'])->name('chats.send-contact');
        Route::post('/chats/{chat}/send-poll', [ChatController::class, 'sendPoll'])->name('chats.send-poll');

        Route::post('/messages/{message}/react', [MessageController::class, 'react'])->name('messages.react');
        Route::post('/messages/{message}/retry', [MessageController::class, 'retry'])->name('messages.retry');
        Route::delete('/messages/{message}', [MessageController::class, 'destroy'])->name('messages.destroy');

        Route::post('/chats/{chat}/assign', [ChatAssignmentController::class, 'store'])->name('chats.assign');
        Route::post('/chats/{chat}/assign/sync', [ChatAssignmentController::class, 'sync'])->name('chats.assign.sync');
        Route::delete('/chats/{chat}/assign/{assignment}', [ChatAssignmentController::class, 'destroy'])->name('chats.unassign');

        Route::get('/communities', [CommunityController::class, 'index'])->name('communities.index');
        Route::post('/communities', [CommunityController::class, 'store'])->name('communities.store');
        Route::get('/communities/{community}', [CommunityController::class, 'show'])->name('communities.show');
        Route::put('/communities/{community}', [CommunityController::class, 'update'])->name('communities.update');
        Route::delete('/communities/{community}', [CommunityController::class, 'destroy'])->name('communities.destroy');
        Route::get('/communities/{community}/available-groups', [CommunityController::class, 'availableGroups'])->name('communities.available-groups');
        Route::post('/communities/{community}/link-group', [CommunityController::class, 'linkGroup'])->name('communities.link-group');
        Route::delete('/communities/{community}/groups/{chat}', [CommunityController::class, 'unlinkGroup'])->name('communities.unlink-group');
    });

    Route::get('/media/{media}', [MediaController::class, 'show'])->name('media.show');

    Route::middleware('role:administrator')->prefix('settings')->group(function (): void {
        Route::get('/connections', [WhatsappSessionController::class, 'index'])->name('settings.connections');
        Route::post('/connections', [WhatsappSessionController::class, 'store'])->name('settings.connections.store');
        Route::patch('/connections/{session}', [WhatsappSessionController::class, 'update'])->name('settings.connections.update');
        Route::post('/connections/{session}/initialize', [WhatsappSessionController::class, 'initialize'])->name('settings.connections.initialize');
        Route::get('/connections/{session}/qr', [WhatsappSessionController::class, 'qr'])->name('settings.connections.qr');
        Route::get('/connections/{session}/diagnostics', [WhatsappSessionController::class, 'diagnostics'])->name('settings.connections.diagnostics');
        Route::get('/connections/{session}/status', [WhatsappSessionController::class, 'status'])->name('settings.connections.status');
        Route::post('/connections/{session}/verify', [WhatsappSessionController::class, 'verify'])->name('settings.connections.verify');
        Route::post('/connections/{session}/logout', [WhatsappSessionController::class, 'logout'])->name('settings.connections.logout');
        Route::delete('/connections/{session}', [WhatsappSessionController::class, 'destroy'])->name('settings.connections.destroy');

        Route::get('/departments', [DepartmentController::class, 'index'])->name('settings.departments');
        Route::post('/departments', [DepartmentController::class, 'store'])->name('settings.departments.store');
        Route::put('/departments/{department}', [DepartmentController::class, 'update'])->name('settings.departments.update');
        Route::delete('/departments/{department}', [DepartmentController::class, 'destroy'])->name('settings.departments.destroy');

        Route::get('/users', [UserManagementController::class, 'index'])->name('settings.users');
        Route::post('/users', [UserManagementController::class, 'store'])->name('settings.users.store');
        Route::put('/users/{user}', [UserManagementController::class, 'update'])->name('settings.users.update');
        Route::delete('/users/{user}', [UserManagementController::class, 'destroy'])->name('settings.users.destroy');

        Route::get('/system', [SettingsController::class, 'index'])->name('settings.system');
        Route::post('/system', [SettingsController::class, 'update'])->name('settings.system.update');
    });

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

Route::middleware(['auth'])->prefix('api')->group(function (): void {
    Route::get('/chats/{chat}/timeline', [ChatController::class, 'timeline'])->name('api.chats.timeline');
});

require __DIR__.'/auth.php';
