<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Services\SuperAdmin\TenantImpersonationService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ImpersonationController extends Controller
{
    public function accept(Request $request, TenantImpersonationService $impersonation): Response
    {
        if (! $request->hasValidSignature()) {
            abort(403, 'Недействительная или просроченная ссылка.');
        }

        $token = (string) $request->query('token', '');
        if ($token === '') {
            abort(403, 'Ссылка для входа недействительна.');
        }

        return $impersonation->accept($request, $token);
    }

    public function destroy(Request $request, TenantImpersonationService $impersonation): Response
    {
        return $impersonation->leave($request);
    }
}
