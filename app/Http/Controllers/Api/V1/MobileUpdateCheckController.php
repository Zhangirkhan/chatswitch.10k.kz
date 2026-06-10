<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\MobileAppRelease;
use App\Services\Mobile\MobileAppReleaseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class MobileUpdateCheckController extends Controller
{
    public function __construct(
        private readonly MobileAppReleaseService $releaseService,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'platform' => ['required', 'string', Rule::in(MobileAppRelease::PLATFORMS)],
            'version_code' => ['required', 'integer', 'min:0'],
            'version_name' => ['nullable', 'string', 'max:32'],
        ]);

        return response()->json([
            'data' => $this->releaseService->checkUpdate(
                $data['platform'],
                (int) $data['version_code'],
            ),
        ]);
    }
}
