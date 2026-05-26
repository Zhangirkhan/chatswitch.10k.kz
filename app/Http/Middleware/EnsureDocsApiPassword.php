<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Защита Swagger UI и openapi.yaml общим паролем (HTTP Basic Auth).
 */
final class EnsureDocsApiPassword
{
    public function handle(Request $request, Closure $next): Response
    {
        $expectedPassword = (string) config('docs.api_password', '');
        $expectedUsername = (string) config('docs.api_username', 'docs');

        if ($expectedPassword === '') {
            abort(503, 'Документация API отключена: не задан DOCS_API_PASSWORD.');
        }

        $username = (string) $request->getUser();
        $password = (string) $request->getPassword();

        if (
            hash_equals($expectedUsername, $username)
            && hash_equals($expectedPassword, $password)
        ) {
            return $next($request);
        }

        return response('Требуется авторизация.', Response::HTTP_UNAUTHORIZED, [
            'WWW-Authenticate' => 'Basic realm="Accel API Docs", charset="UTF-8"',
        ]);
    }
}
