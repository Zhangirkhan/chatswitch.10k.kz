<?php

declare(strict_types=1);

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\UserFeedback;
use App\Services\Feedback\UserFeedbackPopularService;
use App\Services\Feedback\UserFeedbackService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class ContactMessageRankingController extends Controller
{
    public function __construct(
        private readonly UserFeedbackPopularService $popularService,
        private readonly UserFeedbackService $feedbackService,
    ) {}

    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'status' => ['nullable', 'string', 'in:new,read,resolved'],
            'type' => ['nullable', 'string', 'in:complaint,suggestion'],
            'period' => ['nullable', 'string', 'in:7d,30d,all'],
        ]);

        return Inertia::render('SuperAdmin/ContactMessages/Ranking', [
            'messages' => $this->popularService->paginateRankingForAdmin($filters),
            'filters' => [
                'status' => $filters['status'] ?? '',
                'type' => $filters['type'] ?? '',
                'period' => $filters['period'] ?? '30d',
            ],
        ]);
    }

    public function markRead(Request $request, UserFeedback $contactMessage): RedirectResponse
    {
        $validated = $request->validate([
            'admin_note' => ['nullable', 'string', 'max:5000'],
        ]);

        $admin = $request->user();
        abort_if($admin === null, 401);

        $this->feedbackService->markAsRead(
            $contactMessage,
            $admin,
            $validated['admin_note'] ?? null,
        );

        return back()->with('success', 'Обращение отмечено как прочитанное.');
    }

    public function resolve(Request $request, UserFeedback $contactMessage): RedirectResponse
    {
        $validated = $request->validate([
            'admin_note' => ['nullable', 'string', 'max:5000'],
        ]);

        $admin = $request->user();
        abort_if($admin === null, 401);

        $this->feedbackService->resolve(
            $contactMessage,
            $admin,
            $validated['admin_note'] ?? null,
        );

        return back()->with('success', 'Обращение закрыто.');
    }
}
