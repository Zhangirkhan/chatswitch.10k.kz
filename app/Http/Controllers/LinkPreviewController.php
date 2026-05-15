<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\LinkPreviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class LinkPreviewController extends Controller
{
    public function __construct(
        private readonly LinkPreviewService $linkPreviewService,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $result = $this->linkPreviewService->previewForRequest($request);
        if (($result['success'] ?? false) !== true) {
            return response()->json($result, 422);
        }

        return response()->json($result);
    }
}
