<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\LandingLocale;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

final class SetLandingLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $locale = LandingLocale::resolve($request);
        app()->setLocale($locale);

        $queryLang = $request->query('lang');
        if (is_string($queryLang) && LandingLocale::isSupported($queryLang)) {
            Cookie::queue(Cookie::make(
                (string) config('landing.cookie_name', 'landing_locale'),
                $locale,
                (int) config('landing.cookie_minutes', 525_600),
                '/',
                null,
                $request->isSecure(),
                false,
            ));
        }

        View::share('landingMeta', LandingLocale::meta($locale, $request->url()));
        View::share('landingLocale', $locale);

        return $next($request);
    }
}
