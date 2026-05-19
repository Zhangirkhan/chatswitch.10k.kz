<?php

use App\Http\Middleware\EnsureActiveUser;
use App\Http\Middleware\EnsureApiUserActive;
use App\Http\Middleware\EnsureSettingsReadiness;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\PreventAuthenticatedDocumentCache;
use App\Http\Middleware\RoleMiddleware;
use App\Http\Middleware\VerifyWhatsappWebhook;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        ['prefix' => 'broadcasting', 'middleware' => ['web']],
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            PreventAuthenticatedDocumentCache::class,
            EnsureActiveUser::class,
        ]);

        $middleware->alias([
            'role' => RoleMiddleware::class,
            'whatsapp.webhook' => VerifyWhatsappWebhook::class,
            'api.active' => EnsureApiUserActive::class,
            'settings.readiness' => EnsureSettingsReadiness::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
