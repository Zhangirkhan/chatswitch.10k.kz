<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final class EnsureActiveUser
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() && ! $request->user()->is_active) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            $context = app(TenantContext::class);

            if ($context->isAdminHost($request->getHost())) {
                return redirect()->route('super.login')->with('error', 'Ваш аккаунт деактивирован.');
            }

            return redirect()->route('login', [
                'tenant' => $context->slug() ?? (string) config('tenancy.fallback_slug', 'demo'),
            ])->with('error', 'Ваш аккаунт деактивирован.');
        }

        return $next($request);
    }
}
