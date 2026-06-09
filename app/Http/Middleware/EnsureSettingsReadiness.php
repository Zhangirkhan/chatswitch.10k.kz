<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\AI\AiReadinessService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Для администратора: не пускать в прочие разделы настроек, пока AI не готов.
 * Во время онбординга доступны маршруты шагов чеклиста (воронки, сотрудники и т.д.).
 */
final class EnsureSettingsReadiness
{
    /** @var list<string> */
    private const ONBOARDING_ROUTE_PREFIXES = [
        'settings.onboarding',
        'settings.connections',
        'settings.users',
        'settings.departments',
        'settings.funnels',
        'settings.knowledge',
        'settings.ai-quality',
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
        if ($this->isOnboardingRoute($routeName)) {
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

    private function isOnboardingRoute(?string $routeName): bool
    {
        if ($routeName === null) {
            return false;
        }

        foreach (self::ONBOARDING_ROUTE_PREFIXES as $prefix) {
            if (str_starts_with($routeName, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
