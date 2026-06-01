<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\SuperAdmin\SuperAdminCompanyScope;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureGlobalSuperAdmin
{
    public function __construct(
        private readonly SuperAdminCompanyScope $scope,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->scope->ensureGlobalSuperAdmin($request->user());

        return $next($request);
    }
}
