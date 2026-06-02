<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\AI\AiReadinessService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Для администратора: не пускать в разделы настроек, пока AI не готов,
 * кроме онбординга и подключения WhatsApp.
 */
final class EnsureSettingsReadiness
{
    /** @var list<string> */
    private const ALLOWED_ROUTE_NAMES = [
        'settings.onboarding',
        'settings.onboarding.complete',
        'settings.connections',
        'settings.connections.bootstrap',
        'settings.connections.store',
        'settings.connections.update',
        'settings.connections.initialize',
        'settings.connections.qr',
        'settings.connections.diagnostics',
        'settings.connections.status',
        'settings.connections.verify',
        'settings.connections.logout',
        'settings.connections.destroy',
    ];

    public function __construct(
        private readonly AiReadinessService $readinessService,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! config('funnel.enforce_settings_readiness_gate', true)) {
            return $next($request);
        }

        $user = $request->user();
        if ($user === null || ! $user->hasRole('administrator')) {
            return $next($request);
        }

        $routeName = $request->route()?->getName();
        if ($routeName !== null && in_array($routeName, self::ALLOWED_ROUTE_NAMES, true)) {
            return $next($request);
        }

        $readiness = $this->readinessService->evaluate();
        if ($readiness['status'] === 'ready') {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Сначала завершите онбординг: подключите WhatsApp, воронку и базу знаний.',
                'redirect' => route('settings.onboarding'),
                'readiness' => $readiness,
            ], 423);
        }

        return redirect()
            ->route('settings.onboarding')
            ->with('warning', 'Завершите настройку компании в онбординге, прежде чем открывать другие разделы.');
    }
}
