<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\AI\AiReadinessService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Для администратора: не пускать в разделы AI/воронок/канала, пока готовность AI не достигнута.
 * Орг-настройки (сотрудники, отделы, система) вынесены в отдельную группу маршрутов без этого middleware.
 */
final class EnsureSettingsReadiness
{
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

        $readiness = $this->readinessService->evaluate();
        if ($readiness['status'] === 'ready') {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Завершите настройку AI: воронки, база знаний и сценарии.',
                'redirect' => route('settings.onboarding'),
                'readiness' => $readiness,
            ], 423);
        }

        return redirect()
            ->route('settings.onboarding')
            ->with('warning', 'Завершите настройку AI и воронок в онбординге, прежде чем открывать этот раздел.');
    }
}
