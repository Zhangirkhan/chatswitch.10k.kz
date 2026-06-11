<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\PlatformBanner\PlatformBannerService;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class MobileBannerController extends Controller
{
    public function __invoke(Request $request, PlatformBannerService $banners): JsonResponse
    {
        $user = $request->user();
        abort_if($user === null, 401);

        $data = $request->validate([
            'locale' => ['nullable', 'string', Rule::in(['ru', 'kk', 'en'])],
        ]);

        $locale = (string) ($data['locale'] ?? app()->getLocale());
        $companyId = app(TenantContext::class)->companyIdOrNull() ?? $user->company_id;

        return response()->json([
            'data' => [
                'banners' => $banners->activeForMobile(
                    $companyId !== null ? (int) $companyId : null,
                    $locale,
                ),
            ],
        ]);
    }
}
