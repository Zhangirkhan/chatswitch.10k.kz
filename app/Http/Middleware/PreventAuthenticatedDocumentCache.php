<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stops proxies and browsers from serving stale HTML (old @vite script hashes).
 */
final class PreventAuthenticatedDocumentCache
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        if ($request->user() !== null) {
            $response->headers->set(
                'Cache-Control',
                'private, no-cache, no-store, must-revalidate',
            );
            $response->headers->set('Pragma', 'no-cache');
        }

        return $response;
    }
}
