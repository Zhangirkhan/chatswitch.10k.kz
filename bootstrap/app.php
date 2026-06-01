<?php

use App\Http\Controllers\HealthController;
use App\Http\Middleware\EnsureActiveCompany;
use App\Http\Middleware\EnsureActiveUser;
use App\Http\Middleware\EnsureApiUserActive;
use App\Http\Middleware\EnsureDocsApiPassword;
use App\Http\Middleware\EnsureSettingsReadiness;
use App\Http\Middleware\EnsureSuperAdmin;
use App\Http\Middleware\EnsureSuperAdminHost;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\PreventAuthenticatedDocumentCache;
use App\Http\Middleware\ResolveTenant;
use App\Http\Middleware\RestrictWhatsappServiceAccess;
use App\Http\Middleware\RoleMiddleware;
use App\Http\Middleware\VerifyWhatsappWebhook;
use App\Tenancy\TenantContext;
use Illuminate\Auth\Middleware\Authorize;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Contracts\Session\Middleware\AuthenticatesSessions;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\HandlePrecognitiveRequests;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Middleware\ThrottleRequestsWithRedis;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
        using: function (): void {
            $rootDomain = (string) config('tenancy.root_domain', 'accel.kz');
            $adminHost = config('tenancy.admin_subdomain', 'app').'.'.$rootDomain;

            // Health-check, доступен на любом хосте (лендинг, супер-админка, тенант).
            Route::get('/up', HealthController::class)->name('health');

            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api-global.php'));

            Route::middleware('web')
                ->domain($rootDomain)
                ->group(base_path('routes/landing.php'));

            Route::middleware(['web', 'super.admin.host'])
                ->domain($adminHost)
                ->group(base_path('routes/admin.php'));

            Route::middleware(['api', 'tenant.resolve'])
                ->prefix('api')
                ->domain('{tenant}.'.$rootDomain)
                ->group(base_path('routes/api-tenant.php'));

            Route::middleware(['web', 'tenant.resolve', 'tenant.active'])
                ->domain('{tenant}.'.$rootDomain)
                ->group(function (): void {
                    require base_path('routes/tenant.php');
                });
        },
    )
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        ['prefix' => 'broadcasting', 'middleware' => ['web', 'tenant.resolve']],
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
            'whatsapp.service' => RestrictWhatsappServiceAccess::class,
            'api.active' => EnsureApiUserActive::class,
            'settings.readiness' => EnsureSettingsReadiness::class,
            'tenant.resolve' => ResolveTenant::class,
            'tenant.active' => EnsureActiveCompany::class,
            'super.admin.host' => EnsureSuperAdminHost::class,
            'super.admin' => EnsureSuperAdmin::class,
            'super.admin.global' => \App\Http\Middleware\EnsureGlobalSuperAdmin::class,
            'docs.password' => EnsureDocsApiPassword::class,
        ]);

        // Гарантируем, что ResolveTenant выполняется до Authenticate — иначе
        // `auth`-мидлвара пытается редиректить на login до того, как мы
        // определили tenant slug, и URL-генератор бросает UrlGenerationException.
        $middleware->priority([
            HandlePrecognitiveRequests::class,
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
            ShareErrorsFromSession::class,
            ResolveTenant::class,
            EnsureActiveCompany::class,
            AuthenticatesRequests::class,
            ThrottleRequests::class,
            ThrottleRequestsWithRedis::class,
            AuthenticatesSessions::class,
            SubstituteBindings::class,
            Authorize::class,
        ]);

        // Куда редиректить гостей при `auth`-мидлваре. Имя `login` существует только
        // у тенантного домена; для супер-админки используем `super.login`.
        $middleware->redirectGuestsTo(function (Request $request): string {
            $rootDomain = (string) config('tenancy.root_domain', 'accel.kz');
            $adminHost = config('tenancy.admin_subdomain', 'app').'.'.$rootDomain;
            $host = strtolower($request->getHost());

            if ($host === $adminHost) {
                return route('super.login', absolute: true);
            }

            $slug = TenantContext::parseSlugFromHost($host, $rootDomain)
                ?? (string) config('tenancy.fallback_slug', 'demo');

            return route('login', ['tenant' => $slug], absolute: true);
        });

        // Уже авторизованный пользователь не должен видеть /login — редирект на дашборд.
        $middleware->redirectUsersTo(function (Request $request): string {
            $rootDomain = (string) config('tenancy.root_domain', 'accel.kz');
            $adminHost = config('tenancy.admin_subdomain', 'app').'.'.$rootDomain;
            $host = strtolower($request->getHost());

            if ($host === $adminHost) {
                $user = $request->user();

                if ($user !== null && ! $user->is_super_admin) {
                    return route('super.login', absolute: true);
                }

                return route('super.dashboard', absolute: true);
            }

            $slug = TenantContext::parseSlugFromHost($host, $rootDomain)
                ?? (string) config('tenancy.fallback_slug', 'demo');

            return route('dashboard', ['tenant' => $slug], absolute: true);
        });
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
