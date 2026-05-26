<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Marks request as super-admin host (no tenant scope). */
final class EnsureSuperAdminHost
{
    public function __construct(
        private readonly TenantContext $tenantContext,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->tenantContext->isAdminHost($request->getHost())) {
            abort(404);
        }

        $this->tenantContext->bypassScopes();

        return $next($request);
    }
}
