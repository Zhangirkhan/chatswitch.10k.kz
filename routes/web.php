<?php

declare(strict_types=1);

use App\Http\Controllers\Analytics\DialogAnalyticsPageController;
use App\Http\Controllers\Api\DialogAnalyticsController;
use App\Http\Controllers\Api\FunnelAnalyticsController;
use App\Http\Controllers\ApiDocumentationController;
use App\Http\Controllers\CalendarController;
use App\Http\Controllers\ChatAiAssistantController;
use App\Http\Controllers\ChatAiSettingsController;
use App\Http\Controllers\ChatAssignmentController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\CommunityController;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\DepartmentController;
use App\Http\Controllers\FunnelController;
use App\Http\Controllers\KnowledgeBaseController;
use App\Http\Controllers\LinkPreviewController;
use App\Http\Controllers\MediaController;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\MessageTranslationController;
use App\Http\Controllers\OrganizationController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ScheduledMessageController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\UserManagementController;
use App\Http\Controllers\WhatsappSessionController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', fn () => redirect()->route('login'));

Route::redirect('/docs/api/mobile-v1', '/docs/api', 301);

Route::prefix('docs/api')->group(function (): void {
    Route::get('/', [ApiDocumentationController::class, 'swagger'])->name('docs.api');
    Route::get('/openapi.yaml', [ApiDocumentationController::class, 'openApiYaml'])->name('docs.api.openapi');
});

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::get('/dashboard', fn () => redirect()->route('chats.index'))->name('dashboard');

    // Visual stubs (WhatsApp Web parity)
    Route::get('/status', fn () => Inertia::render('Status/Index'))->name('status.index');
    Route::get('/channels', fn () => Inertia::render('Channels/Index'))->name('channels.index');

    Route::middleware('role:administrator,manager,employee')->group(function (): void {
        Route::get('/chats', [ChatController::class, 'index'])->name('chats.index');
        Route::get('/chats/feed', [ChatController::class, 'feed'])->name('chats.feed');
        Route::get('/chats/archived', [ChatController::class, 'archivedIndex'])->name('chats.archived');
        Route::get('/chats/contacts', [ChatController::class, 'contacts'])->name('chats.contacts');
        Route::get('/contacts', [ContactController::class, 'index'])->name('contacts.index');
        Route::patch('/contacts/{contact}', [ContactController::class, 'update'])->name('contacts.update');
        Route::get('/contacts/{contact}/card', [ContactController::class, 'card'])->name('contacts.card');
        Route::post('/contacts/upsert', [ContactController::class, 'upsert'])->name('contacts.upsert');
        Route::post('/chats/start', [ChatController::class, 'start'])->name('chats.start');
        Route::post('/chats/create-group', [ChatController::class, 'createGroup'])->name('chats.create-group');
        Route::post('/chats/sync-groups', [ChatController::class, 'syncGroups'])->name('chats.sync-groups');
        Route::get('/chats/{chat}/participants', [ChatController::class, 'groupParticipants'])->name('chats.group-participants');
        Route::get('/chats/{chat}', [ChatController::class, 'show'])->name('chats.show');
        Route::post('/chats/{chat}/send-message', [ChatController::class, 'sendMessage'])->name('chats.send-message');
        Route::get('/chats/{chat}/scheduled-messages', [ScheduledMessageController::class, 'index'])->name('chats.scheduled-messages.index');
        Route::post('/chats/{chat}/scheduled-messages', [ScheduledMessageController::class, 'store'])->name('chats.scheduled-messages.store');
        Route::put('/chats/{chat}/scheduled-messages/{scheduledMessage}', [ScheduledMessageController::class, 'update'])->name('chats.scheduled-messages.update');
        Route::delete('/chats/{chat}/scheduled-messages/{scheduledMessage}', [ScheduledMessageController::class, 'destroy'])->name('chats.scheduled-messages.destroy');
        Route::post('/chats/{chat}/typing', [ChatController::class, 'typing'])->name('chats.typing');
        Route::post('/chats/{chat}/mark-read', [ChatController::class, 'markRead'])->name('chats.mark-read');
        Route::post('/chats/{chat}/toggle-pin', [ChatController::class, 'togglePin'])->name('chats.toggle-pin');
        Route::post('/chats/{chat}/pin-message', [ChatController::class, 'pinMessage'])->name('chats.pin-message');
        Route::delete('/chats/{chat}/pin-message', [ChatController::class, 'unpinMessage'])->name('chats.unpin-message');
        Route::post('/chats/{chat}/archive', [ChatController::class, 'archive'])->name('chats.archive');
        Route::post('/chats/{chat}/toggle-mute', [ChatController::class, 'toggleMute'])->name('chats.toggle-mute');
        Route::post('/chats/{chat}/toggle-favorite', [ChatController::class, 'toggleFavorite'])->name('chats.toggle-favorite');
        Route::post('/chats/{chat}/toggle-unread', [ChatController::class, 'toggleUnread'])->name('chats.toggle-unread');
        Route::post('/chats/{chat}/clear', [ChatController::class, 'clear'])->name('chats.clear');
        Route::post('/chats/{chat}/save-contact', [ChatController::class, 'saveContact'])->name('chats.save-contact');
        Route::get('/chats/{chat}/media-links-documents', [ChatController::class, 'mediaLinksDocuments'])->name('chats.media-links-documents');
        Route::post('/chats/{chat}/departments', [ChatController::class, 'syncDepartments'])->name('chats.departments.sync');
        Route::get('/chats/{chat}/departments/history', [ChatController::class, 'departmentHistory'])->name('chats.departments.history');
        Route::post('/chats/{chat}/ai/chat', [ChatAiAssistantController::class, 'chat'])
            ->middleware('throttle:30,1')
            ->name('chats.ai.chat');
        Route::patch('/chats/{chat}/ai', [ChatAiSettingsController::class, 'update'])->name('chats.ai.update');

        Route::post('/chats/{chat}/upload-file', [ChatController::class, 'uploadFile'])->name('chats.upload-file');
        Route::post('/chats/{chat}/send-contact', [ChatController::class, 'sendContact'])->name('chats.send-contact');
        Route::post('/chats/{chat}/send-poll', [ChatController::class, 'sendPoll'])->name('chats.send-poll');

        Route::post('/messages/{message}/react', [MessageController::class, 'react'])->name('messages.react');
        Route::post('/messages/{message}/retry', [MessageController::class, 'retry'])->name('messages.retry');
        Route::post('/messages/{message}/forward', [MessageController::class, 'forward'])->name('messages.forward');
        Route::post('/messages/forward-bulk', [MessageController::class, 'forwardBulk'])->name('messages.forward-bulk');
        Route::delete('/messages/{message}', [MessageController::class, 'destroy'])->name('messages.destroy');
        Route::post('/messages/{message}/translate', [MessageTranslationController::class, 'translate'])->name('messages.translate');

        Route::get('/link-preview', LinkPreviewController::class)->name('link-preview');

        Route::post('/chats/{chat}/assign', [ChatAssignmentController::class, 'store'])->name('chats.assign');
        Route::post('/chats/{chat}/assign/sync', [ChatAssignmentController::class, 'sync'])->name('chats.assign.sync');
        Route::get('/chats/{chat}/assign/history', [ChatAssignmentController::class, 'history'])->name('chats.assign.history');
        Route::delete('/chats/{chat}/assign/{assignment}', [ChatAssignmentController::class, 'destroy'])->name('chats.unassign');

        Route::get('/communities', [CommunityController::class, 'index'])->name('communities.index');
        Route::post('/communities', [CommunityController::class, 'store'])->name('communities.store');
        Route::get('/communities/{community}', [CommunityController::class, 'show'])->name('communities.show');
        Route::put('/communities/{community}', [CommunityController::class, 'update'])->name('communities.update');
        Route::delete('/communities/{community}', [CommunityController::class, 'destroy'])->name('communities.destroy');
        Route::get('/communities/{community}/available-groups', [CommunityController::class, 'availableGroups'])->name('communities.available-groups');
        Route::post('/communities/{community}/link-group', [CommunityController::class, 'linkGroup'])->name('communities.link-group');
        Route::delete('/communities/{community}/groups/{chat}', [CommunityController::class, 'unlinkGroup'])->name('communities.unlink-group');

        Route::get('/analytics/dialogs', DialogAnalyticsPageController::class)->name('analytics.dialogs');

        // Календарь записей
        Route::get('/calendar', [CalendarController::class, 'index'])->name('calendar.index');
        Route::get('/calendar/events', [CalendarController::class, 'events'])->name('calendar.events');
        Route::post('/calendar/events', [CalendarController::class, 'store'])->name('calendar.events.store');
        Route::put('/calendar/events/{event}', [CalendarController::class, 'update'])->name('calendar.events.update');
        Route::delete('/calendar/events/{event}', [CalendarController::class, 'destroy'])->name('calendar.events.destroy');

        Route::get('/organization', [OrganizationController::class, 'index'])->name('organization.index');
        Route::get('/organization/archive', [OrganizationController::class, 'archive'])->name('organization.archive');
        Route::get('/organization/departments/{department}', [OrganizationController::class, 'showDepartment'])->name('organization.departments.show');
        Route::post('/organization/departments/{department}/posts', [OrganizationController::class, 'storePost'])->name('organization.posts.store');
        Route::get('/organization/posts/{post}', [OrganizationController::class, 'showPost'])->name('organization.posts.show');
        Route::patch('/organization/posts/{post}', [OrganizationController::class, 'updatePost'])->name('organization.posts.update');
        Route::delete('/organization/posts/{post}', [OrganizationController::class, 'destroyPost'])->name('organization.posts.destroy');
        Route::post('/organization/posts/{post}/comments', [OrganizationController::class, 'storeComment'])->name('organization.posts.comments.store');
        Route::delete('/organization/posts/{post}/comments/{comment}', [OrganizationController::class, 'destroyComment'])->name('organization.posts.comments.destroy');
        Route::post('/organization/posts/{post}/attachments', [OrganizationController::class, 'storeAttachment'])->name('organization.posts.attachments.store');
        Route::delete('/organization/posts/{post}/attachments/{attachment}', [OrganizationController::class, 'destroyAttachment'])->name('organization.posts.attachments.destroy');
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
        Route::post('/departments/{department}/members', [DepartmentController::class, 'syncMembers'])->name('settings.departments.members.sync');
        Route::delete('/departments/{department}', [DepartmentController::class, 'destroy'])->name('settings.departments.destroy');

        Route::get('/funnels', [FunnelController::class, 'index'])->name('settings.funnels');
        Route::post('/funnels', [FunnelController::class, 'store'])->name('settings.funnels.store');
        Route::put('/funnels/{funnel}', [FunnelController::class, 'update'])->name('settings.funnels.update');
        Route::delete('/funnels/{funnel}', [FunnelController::class, 'destroy'])->name('settings.funnels.destroy');
        Route::post('/funnels/{funnel}/stages', [FunnelController::class, 'storeStage'])->name('settings.funnels.stages.store');
        Route::put('/funnels/{funnel}/stages/{stage}', [FunnelController::class, 'updateStage'])->name('settings.funnels.stages.update');
        Route::delete('/funnels/{funnel}/stages/{stage}', [FunnelController::class, 'destroyStage'])->name('settings.funnels.stages.destroy');
        Route::post('/funnels/{funnel}/stages/reorder', [FunnelController::class, 'reorderStages'])->name('settings.funnels.stages.reorder');

        Route::get('/users', [UserManagementController::class, 'index'])->name('settings.users');
        Route::post('/users', [UserManagementController::class, 'store'])->name('settings.users.store');
        Route::put('/users/{user}', [UserManagementController::class, 'update'])->name('settings.users.update');
        Route::delete('/users/{user}', [UserManagementController::class, 'destroy'])->name('settings.users.destroy');

        Route::get('/clients', [ContactController::class, 'settingsIndex'])->name('settings.clients');
        Route::patch('/clients/{contact}/companies', [ContactController::class, 'syncCompanies'])->name('settings.clients.companies.sync');
        Route::post('/companies', [CompanyController::class, 'store'])->name('settings.companies.store');
        Route::put('/companies/{company}', [CompanyController::class, 'update'])->name('settings.companies.update');
        Route::delete('/companies/{company}', [CompanyController::class, 'destroy'])->name('settings.companies.destroy');

        Route::get('/knowledge/prompt-preview', [KnowledgeBaseController::class, 'promptPreview'])->name('settings.knowledge.prompt-preview');
        Route::get('/knowledge/products', [KnowledgeBaseController::class, 'products'])->name('settings.knowledge.products');
        Route::post('/knowledge/products', [KnowledgeBaseController::class, 'storeProduct'])->name('settings.knowledge.products.store');
        Route::post('/knowledge/products/bulk-prompt', [KnowledgeBaseController::class, 'bulkProductsPrompt'])->name('settings.knowledge.products.bulk-prompt');
        Route::put('/knowledge/products/{product}', [KnowledgeBaseController::class, 'updateProduct'])->name('settings.knowledge.products.update');
        Route::delete('/knowledge/products/{product}', [KnowledgeBaseController::class, 'destroyProduct'])->name('settings.knowledge.products.destroy');
        Route::get('/knowledge/services', [KnowledgeBaseController::class, 'services'])->name('settings.knowledge.services');
        Route::post('/knowledge/services', [KnowledgeBaseController::class, 'storeService'])->name('settings.knowledge.services.store');
        Route::post('/knowledge/services/bulk-prompt', [KnowledgeBaseController::class, 'bulkServicesPrompt'])->name('settings.knowledge.services.bulk-prompt');
        Route::put('/knowledge/services/{service}', [KnowledgeBaseController::class, 'updateService'])->name('settings.knowledge.services.update');
        Route::delete('/knowledge/services/{service}', [KnowledgeBaseController::class, 'destroyService'])->name('settings.knowledge.services.destroy');
        Route::get('/knowledge/rules', [KnowledgeBaseController::class, 'rules'])->name('settings.knowledge.rules');
        Route::post('/knowledge/rules', [KnowledgeBaseController::class, 'storeRule'])->name('settings.knowledge.rules.store');
        Route::post('/knowledge/rules/bulk-prompt', [KnowledgeBaseController::class, 'bulkRulesPrompt'])->name('settings.knowledge.rules.bulk-prompt');
        Route::put('/knowledge/rules/{rule}', [KnowledgeBaseController::class, 'updateRule'])->name('settings.knowledge.rules.update');
        Route::delete('/knowledge/rules/{rule}', [KnowledgeBaseController::class, 'destroyRule'])->name('settings.knowledge.rules.destroy');

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

Route::middleware(['auth', 'role:administrator,manager,employee'])->prefix('api')->group(function (): void {
    Route::get('/analytics/dialogs', DialogAnalyticsController::class)->name('api.analytics.dialogs');
    Route::get('/analytics/funnels', FunnelAnalyticsController::class)->name('api.analytics.funnels');
});

require __DIR__.'/auth.php';
