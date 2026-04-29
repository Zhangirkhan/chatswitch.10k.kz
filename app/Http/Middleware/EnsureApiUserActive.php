<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureApiUserActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user !== null && ! $user->is_active) {
            return response()->json([
                'message' => 'Ваш аккаунт деактивирован.',
            ], 403);
        }

        return $next($request);
    }
}
