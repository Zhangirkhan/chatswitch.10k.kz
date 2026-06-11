<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\FeedbackPopularResource;
use App\Models\UserFeedback;
use App\Services\Feedback\UserFeedbackPopularService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class FeedbackPopularController extends Controller
{
    public function __construct(
        private readonly UserFeedbackPopularService $popularService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_if($user === null, 401);

        $filters = $request->validate([
            'type' => ['nullable', 'string', 'in:complaint,suggestion'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'sort' => ['nullable', 'string', 'in:likes_desc'],
        ]);

        $entries = $this->popularService->popularForUser($user, $filters);

        return response()->json([
            'data' => [
                'entries' => FeedbackPopularResource::collection($entries)->resolve(),
            ],
        ]);
    }

    public function like(Request $request, UserFeedback $feedback): JsonResponse
    {
        $user = $request->user();
        abort_if($user === null, 401);

        $result = $this->popularService->like($user, $feedback);

        return response()->json(['data' => $result]);
    }

    public function unlike(Request $request, UserFeedback $feedback): JsonResponse
    {
        $user = $request->user();
        abort_if($user === null, 401);

        $result = $this->popularService->unlike($user, $feedback);

        return response()->json(['data' => $result]);
    }
}
