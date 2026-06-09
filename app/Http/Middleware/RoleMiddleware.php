<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\TenantAuthorizer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RoleMiddleware
{
    /** @var array<string, list<string>> */
    private const ROLE_PERMISSION_FALLBACK = [
        'administrator' => ['settings.manage', 'chats.view_all', 'whatsapp.manage'],
        'manager' => ['chats.view_department', 'broadcasts.manage', 'funnels.view'],
        'employee' => ['chats.view_assigned', 'chats.view_department', 'chats.send'],
    ];

    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if ($user === null) {
            abort(403, 'У вас нет доступа к этому разделу.');
        }

        foreach ($roles as $role) {
            if ($user->hasRole($role)) {
                return $next($request);
            }

            $permissions = self::ROLE_PERMISSION_FALLBACK[$role] ?? [];
            if ($permissions !== [] && TenantAuthorizer::canAny($user, $permissions)) {
                return $next($request);
            }
        }

        abort(403, 'У вас нет доступа к этому разделу.');
    }
}
