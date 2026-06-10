<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\UserFeedbackSource;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreFeedbackRequest;
use App\Http\Resources\Api\V1\FeedbackResource;
use App\Services\Feedback\UserFeedbackService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class FeedbackController extends Controller
{
    public function __construct(
        private readonly UserFeedbackService $feedbackService,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();
        abort_if($user === null, 401);

        $items = $this->feedbackService->recentForUser($user, 30);

        return FeedbackResource::collection($items);
    }

    public function store(StoreFeedbackRequest $request): JsonResponse
    {
        $user = $request->user();
        abort_if($user === null, 401);

        $feedback = $this->feedbackService->create(
            $user,
            UserFeedbackSource::Mobile,
            $request->validated(),
        );

        return (new FeedbackResource($feedback))
            ->response()
            ->setStatusCode(201);
    }
}
