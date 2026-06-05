<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Явная проверка: Sanctum-пользователь принадлежит текущему тенанту (поддомену).
 */
final class EnsureUserBelongsToTenant
{
    public function __construct(
        private readonly TenantContext $tenantContext,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user === null || ! $this->tenantContext->isResolved()) {
            return $next($request);
        }

        if ((int) $user->company_id !== $this->tenantContext->companyId()) {
            return response()->json([
                'message' => 'Токен не действителен для этого рабочего пространства.',
            ], 403);
        }

        return $next($request);
    }
}
