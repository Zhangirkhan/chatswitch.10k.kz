<?php

declare(strict_types=1);

use App\Http\Controllers\AiWorkspaceController;
use App\Http\Controllers\Api\DialogAnalyticsController;
use App\Http\Controllers\Api\FunnelAnalyticsController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\ChatController;
use App\Http\Controllers\Api\V1\ContactController as ApiContactController;
use App\Http\Controllers\Api\V1\DepartmentController;
use App\Http\Controllers\Api\V1\MediaController;
use App\Http\Controllers\Api\V1\SettingsController as ApiSettingsController;
use App\Http\Controllers\Api\V1\WorkspaceController;
use App\Http\Controllers\BroadcastController;
use App\Http\Controllers\CalendarController;
use App\Http\Controllers\ChatAiAssistantController;
use App\Http\Controllers\ChatAiSettingsController;
use App\Http\Controllers\ChatAssignmentController;
use App\Http\Controllers\ChatController as WebChatController;
use App\Http\Controllers\ChatFunnelController;
use App\Http\Controllers\CommunityController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\FunnelBoardController;
use App\Http\Controllers\FunnelController;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\MessageTranslationController;
use App\Http\Controllers\OrganizationTeamChatController;
use App\Http\Controllers\ScheduledMessageController;
use App\Http\Controllers\WhatsappSessionController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware(['throttle:api'])->group(function (): void {
    Route::get('workspace', [WorkspaceController::class, 'show']);
    Route::post('auth/login', [AuthController::class, 'login']);
    Route::post('auth/login/pin', [AuthController::class, 'loginPin']);

    Route::middleware(['auth:sanctum', 'api.active', 'role:administrator,manager,employee'])->group(function (): void {
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::get('auth/me', [AuthController::class, 'me']);

        Route::get('settings', [ApiSettingsController::class, 'show']);
        Route::get('departments', [DepartmentController::class, 'index']);
        Route::get('whatsapp/sessions', [WhatsappSessionController::class, 'bootstrap']);

        Route::get('contacts', [ApiContactController::class, 'index']);
        Route::get('contacts/picker', [WebChatController::class, 'contacts']);
        Route::get('contacts/{contact}/card', [ContactController::class, 'card']);
        Route::get('contacts/{contact}/profile', [ContactController::class, 'clientProfile']);
        Route::get('contacts/{contact}/summary', [ContactController::class, 'clientSummary']);
        Route::patch('contacts/{contact}', [ContactController::class, 'update']);
        Route::patch('contacts/{contact}/fields', [ContactController::class, 'updateFields']);
        Route::post('contacts/upsert', [ContactController::class, 'upsert']);

        Route::get('chats', [ChatController::class, 'index']);
        Route::get('chats/archived', [ChatController::class, 'archivedIndex']);
        Route::get('chats/{chat}', [ChatController::class, 'show']);
        Route::get('chats/{chat}/messages', [ChatController::class, 'messages']);
        Route::post('chats/{chat}/messages', [ChatController::class, 'storeMessage']);
        Route::post('chats/{chat}/read', [ChatController::class, 'markRead']);
        Route::post('chats/{chat}/typing', [ChatController::class, 'typing']);
        Route::post('chats/{chat}/ai/chat', [ChatAiAssistantController::class, 'chat'])
            ->middleware('throttle:30,1');
        Route::patch('chats/{chat}/ai', [ChatAiSettingsController::class, 'updateForApi'])
            ->middleware('throttle:30,1');

        Route::post('chats/{chat}/pin', [WebChatController::class, 'togglePin']);
        Route::post('chats/{chat}/pin-message', [WebChatController::class, 'pinMessage']);
        Route::delete('chats/{chat}/pin-message', [WebChatController::class, 'unpinMessage']);
        Route::post('chats/{chat}/archive', [WebChatController::class, 'archive']);
        Route::post('chats/{chat}/mute', [WebChatController::class, 'toggleMute']);
        Route::post('chats/{chat}/favorite', [WebChatController::class, 'toggleFavorite']);
        Route::post('chats/{chat}/unread', [WebChatController::class, 'toggleUnread']);
        Route::post('chats/{chat}/clear', [WebChatController::class, 'clear']);
        Route::post('chats/{chat}/upload', [WebChatController::class, 'uploadFile'])
            ->middleware('throttle:chat-send');
        Route::post('chats/{chat}/assign', [ChatAssignmentController::class, 'store']);
        Route::post('chats/{chat}/assign/sync', [ChatAssignmentController::class, 'sync']);
        Route::get('chats/{chat}/assign/history', [ChatAssignmentController::class, 'history']);
        Route::delete('chats/{chat}/assign/{assignment}', [ChatAssignmentController::class, 'destroy']);
        Route::patch('chats/{chat}/funnel', [ChatFunnelController::class, 'update']);
        Route::get('chats/{chat}/funnel/history', [ChatFunnelController::class, 'history']);

        Route::get('chats/{chat}/scheduled-messages', [ScheduledMessageController::class, 'index']);
        Route::post('chats/{chat}/scheduled-messages', [ScheduledMessageController::class, 'store']);
        Route::put('chats/{chat}/scheduled-messages/{scheduledMessage}', [ScheduledMessageController::class, 'update']);
        Route::delete('chats/{chat}/scheduled-messages/{scheduledMessage}', [ScheduledMessageController::class, 'destroy']);

        Route::post('messages/{message}/react', [MessageController::class, 'react']);
        Route::post('messages/{message}/forward', [MessageController::class, 'forward']);
        Route::post('messages/{message}/translate', [MessageTranslationController::class, 'translate'])
            ->middleware('throttle:30,1');
        Route::delete('messages/{message}', [MessageController::class, 'destroy']);
        Route::post('messages/{message}/retry', [MessageController::class, 'retry']);

        Route::get('media/{media}', [MediaController::class, 'show'])->name('api.v1.media.show');

        Route::prefix('team-chat')->group(function (): void {
            Route::get('conversations', [OrganizationTeamChatController::class, 'conversations']);
            Route::get('contacts', [OrganizationTeamChatController::class, 'contacts']);
            Route::get('search', [OrganizationTeamChatController::class, 'search']);
            Route::post('direct', [OrganizationTeamChatController::class, 'openDirect']);
            Route::get('{team_conversation}/participants', [OrganizationTeamChatController::class, 'participants']);
            Route::get('{team_conversation}/read-meta', [OrganizationTeamChatController::class, 'readMeta']);
            Route::get('{team_conversation}/messages', [OrganizationTeamChatController::class, 'messages']);
            Route::post('{team_conversation}/messages', [OrganizationTeamChatController::class, 'storeMessage']);
            Route::post('{team_conversation}/pin', [OrganizationTeamChatController::class, 'setPinned']);
            Route::post('{team_conversation}/pinned-message', [OrganizationTeamChatController::class, 'setRoomPinnedMessage']);
            Route::post('{team_conversation}/read', [OrganizationTeamChatController::class, 'markRead']);
            Route::post('{team_conversation}/delivered', [OrganizationTeamChatController::class, 'markDelivered']);
            Route::post('{team_conversation}/typing', [OrganizationTeamChatController::class, 'typing']);
            Route::post('{team_conversation}/messages/{team_message}/react', [OrganizationTeamChatController::class, 'reactToMessage']);
            Route::post('messages/{team_message}/share-to-clients', [OrganizationTeamChatController::class, 'shareToClients']);
        });

        Route::post('ai-chat/query', [AiWorkspaceController::class, 'query'])
            ->middleware('throttle:30,1');
        Route::get('ai-chat/clients/{contact}/summary', [AiWorkspaceController::class, 'clientSummary'])
            ->middleware('throttle:30,1');

        Route::get('analytics/dialogs', DialogAnalyticsController::class);
        Route::get('analytics/funnels', FunnelAnalyticsController::class);

        Route::get('calendar/events', [CalendarController::class, 'events']);
        Route::post('calendar/events', [CalendarController::class, 'store']);
        Route::put('calendar/events/{event}', [CalendarController::class, 'update']);
        Route::delete('calendar/events/{event}', [CalendarController::class, 'destroy']);

        Route::get('communities', [CommunityController::class, 'index']);
        Route::post('communities', [CommunityController::class, 'store']);
        Route::get('communities/{community}', [CommunityController::class, 'show']);
        Route::put('communities/{community}', [CommunityController::class, 'update']);
        Route::delete('communities/{community}', [CommunityController::class, 'destroy']);
        Route::get('communities/{community}/available-groups', [CommunityController::class, 'availableGroups']);
        Route::post('communities/{community}/link-group', [CommunityController::class, 'linkGroup']);
        Route::delete('communities/{community}/groups/{chat}', [CommunityController::class, 'unlinkGroup']);

        Route::middleware('role:administrator,manager')->group(function (): void {
            Route::get('broadcasts', [BroadcastController::class, 'apiIndex']);
            Route::get('broadcasts/{campaign}', [BroadcastController::class, 'show']);
            Route::post('broadcasts/preview', [BroadcastController::class, 'preview']);
            Route::post('broadcasts', [BroadcastController::class, 'store'])
                ->middleware('throttle:10,1');
        });

        Route::middleware('role:administrator')->group(function (): void {
            Route::post('funnels', [FunnelController::class, 'store']);
            Route::put('funnels/{funnel}', [FunnelController::class, 'update']);
            Route::delete('funnels/{funnel}', [FunnelController::class, 'destroy']);
            Route::post('funnels/{funnel}/stages', [FunnelController::class, 'storeStage']);
            Route::put('funnels/{funnel}/stages/{stage}', [FunnelController::class, 'updateStage']);
            Route::delete('funnels/{funnel}/stages/{stage}', [FunnelController::class, 'destroyStage']);
            Route::post('funnels/{funnel}/stages/reorder', [FunnelController::class, 'reorderStages']);
            Route::patch('funnels/{funnel}/ai-scenario', [FunnelController::class, 'updateAiScenario']);
            Route::patch('funnels/{funnel}/stages/{stage}/ai-rule', [FunnelController::class, 'updateStageAiRule']);
            Route::post('funnels/ai-suggest', [FunnelController::class, 'aiSuggest']);
            Route::post('funnels/ai-onboarding-suggest', [FunnelController::class, 'aiOnboardingSuggest']);
        });

        Route::get('funnels/active', [FunnelBoardController::class, 'active']);
        Route::get('funnels/board/data', [FunnelBoardController::class, 'data']);
        Route::get('funnels/board/stage-cards', [FunnelBoardController::class, 'stageCards']);
        Route::get('funnels/board/card/{chat}', [FunnelBoardController::class, 'card']);
        Route::post('funnels/board/bulk-move', [FunnelBoardController::class, 'bulkMove']);
    });
});
