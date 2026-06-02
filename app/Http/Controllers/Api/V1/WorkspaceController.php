<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\TenantResource;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;

final class WorkspaceController extends Controller
{
    public function show(TenantContext $tenantContext): JsonResponse
    {
        $company = $tenantContext->company();

        if ($company === null) {
            return response()->json([
                'message' => 'Tenant not found.',
            ], 404);
        }

        return (new TenantResource($company))->response();
    }
}
