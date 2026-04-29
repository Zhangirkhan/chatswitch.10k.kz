<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Не кэшировать HTML с @vite/Inertia: иначе после деплоя остаются старые хеши
 * (`app-XXXX.css` → 404, пока не сделают жёсткое обновление).
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

        $contentType = (string) $response->headers->get('Content-Type', '');
        if (! str_contains($contentType, 'text/html')) {
            return $response;
        }

        $response->headers->set(
            'Cache-Control',
            'private, no-cache, no-store, must-revalidate',
        );
        $response->headers->set('Pragma', 'no-cache');

        return $response;
    }
}
