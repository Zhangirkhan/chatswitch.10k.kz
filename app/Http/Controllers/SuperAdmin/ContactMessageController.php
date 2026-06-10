<?php

declare(strict_types=1);

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\UserFeedback;
use App\Services\Feedback\UserFeedbackService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class ContactMessageController extends Controller
{
    public function __construct(
        private readonly UserFeedbackService $feedbackService,
    ) {}

    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'status' => ['nullable', 'string', 'in:new,read,resolved'],
            'type' => ['nullable', 'string', 'in:complaint,suggestion'],
            'source' => ['nullable', 'string', 'in:web,mobile'],
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'search' => ['nullable', 'string', 'max:120'],
        ]);

        return Inertia::render('SuperAdmin/ContactMessages/Index', [
            'messages' => $this->feedbackService->paginateForAdmin($filters),
            'filters' => [
                'status' => $filters['status'] ?? '',
                'type' => $filters['type'] ?? '',
                'source' => $filters['source'] ?? '',
                'company_id' => isset($filters['company_id']) ? (string) $filters['company_id'] : '',
                'search' => $filters['search'] ?? '',
            ],
            'companies' => Company::query()->orderBy('name')->get(['id', 'name', 'slug']),
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
