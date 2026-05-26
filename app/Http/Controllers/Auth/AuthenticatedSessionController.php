<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\PinLoginRequest;
use App\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;

class AuthenticatedSessionController extends Controller
{
    /**
     * Display the login view.
     */
    public function create(Request $request): Response|RedirectResponse
    {
        if (Auth::check()) {
            $context = app(TenantContext::class);

            if ($context->isAdminHost($request->getHost())) {
                if (! Auth::user()?->is_super_admin) {
                    Auth::logout();
                    $request->session()->invalidate();
                    $request->session()->regenerateToken();

                    return redirect()
                        ->route('super.login')
                        ->with('error', 'Для супер-админки используйте super@accel.kz');
                }

                return redirect()->route('super.dashboard');
            }

            $slug = $context->slug()
                ?? TenantContext::parseSlugFromHost($request->getHost(), (string) config('tenancy.root_domain', 'accel.kz'))
                ?? (string) config('tenancy.fallback_slug', 'demo');

            return redirect()->route('dashboard', ['tenant' => $slug]);
        }

        $context = app(TenantContext::class);

        return Inertia::render('Auth/Login', [
            'canResetPassword' => Route::has('password.request'),
            'status' => session('status'),
            'pinLoginAvailable' => ! $context->isAdminHost($request->getHost()),
        ]);
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        $request->session()->regenerate();

        $context = app(TenantContext::class);

        if ($context->isAdminHost($request->getHost())) {
            return redirect()->intended(route('super.dashboard', absolute: false));
        }

        $slug = $context->slug()
            ?? TenantContext::parseSlugFromHost($request->getHost(), (string) config('tenancy.root_domain', 'accel.kz'))
            ?? (string) config('tenancy.fallback_slug', 'demo');

        return redirect()->intended(route('dashboard', ['tenant' => $slug], absolute: false));
    }

    public function storePin(PinLoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        $request->session()->regenerate();

        $context = app(TenantContext::class);
        $slug = $context->slug()
            ?? TenantContext::parseSlugFromHost($request->getHost(), (string) config('tenancy.root_domain', 'accel.kz'))
            ?? (string) config('tenancy.fallback_slug', 'demo');

        return redirect()->intended(route('dashboard', ['tenant' => $slug], absolute: false));
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        if (app(TenantContext::class)->isAdminHost($request->getHost())) {
            return redirect()->route('super.login');
        }

        return redirect()->route('login', [
            'tenant' => app(TenantContext::class)->slug()
                ?? (string) config('tenancy.fallback_slug', 'demo'),
        ]);
    }
}
