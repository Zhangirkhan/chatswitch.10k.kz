<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\UserFeedbackSource;
use App\Http\Requests\StoreUserFeedbackRequest;
use App\Services\Feedback\UserFeedbackService;
use Illuminate\Http\RedirectResponse;

final class ProfileFeedbackController extends Controller
{
    public function __construct(
        private readonly UserFeedbackService $feedbackService,
    ) {}

    public function store(StoreUserFeedbackRequest $request): RedirectResponse
    {
        $user = $request->user();
        abort_if($user === null, 401);

        $this->feedbackService->create(
            $user,
            UserFeedbackSource::Web,
            $request->validated(),
        );

        return redirect()
            ->route('profile.edit', ['section' => 'contact'])
            ->with('success', 'Обращение отправлено. Спасибо!');
    }
}
