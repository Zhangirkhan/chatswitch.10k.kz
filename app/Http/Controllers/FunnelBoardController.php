<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Chat;
use App\Models\SystemSetting;
use App\Services\Funnel\FunnelBoardBulkMoveService;
use App\Services\Funnel\FunnelBoardService;
use App\Support\FunnelBoardFilters;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class FunnelBoardController extends Controller
{
    public function __construct(
        private readonly FunnelBoardService $boardService,
        private readonly FunnelBoardBulkMoveService $bulkMoveService,
    ) {}

    public function index(Request $request): Response
    {
        $this->ensureModuleEnabled();

        $user = $request->user();
        abort_unless($user !== null, 403);

        $filters = FunnelBoardFilters::fromRequest($request->query());

        $funnels = $this->boardService->activeFunnels()
            ->map(static fn ($funnel): array => [
                'id' => (int) $funnel->id,
                'name' => (string) $funnel->name,
                'color' => (string) ($funnel->color ?: '#01b964'),
                'description' => $funnel->description,
            ])
            ->values()
            ->all();

        $requestedFunnelId = (int) $request->query('funnel_id', 0);
        $defaultFunnelId = $requestedFunnelId > 0 && collect($funnels)->contains('id', $requestedFunnelId)
            ? $requestedFunnelId
            : ($funnels[0]['id'] ?? null);

        $board = $defaultFunnelId !== null
            ? $this->boardService->board($user, $defaultFunnelId, $filters)
            : ['funnel' => null, 'stages' => []];

        return Inertia::render('Funnels/Board', [
            'funnels' => $funnels,
            'selectedFunnelId' => $defaultFunnelId,
            'filters' => $filters->toQueryParams(),
            'board' => $board,
            'canFilterAll' => $user->hasRole('administrator'),
            'canUseAdvancedFilters' => $user->hasAnyRole(['administrator', 'manager']),
            'filterAssignees' => $this->boardService->filterAssignees($user),
            'filterDepartments' => $this->boardService->filterDepartments($user),
            'filterWhatsappSessions' => $this->boardService->filterWhatsappSessions($user),
        ]);
    }

    public function data(Request $request): JsonResponse
    {
        $this->ensureModuleEnabled();

        $validated = $request->validate([
            'funnel_id' => ['required', 'integer', 'exists:funnels,id'],
            'scope' => ['nullable', 'string', 'in:all,mine,department'],
            'assignee_id' => ['nullable', 'integer', 'exists:users,id'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'whatsapp_session_id' => ['nullable', 'integer', 'exists:whatsapp_sessions,id'],
            'search' => ['nullable', 'string', 'max:120'],
        ]);

        $user = $request->user();
        abort_unless($user !== null, 403);

        $filters = FunnelBoardFilters::fromRequest($validated);

        return response()->json(
            $this->boardService->board($user, (int) $validated['funnel_id'], $filters),
        );
    }

    public function card(Request $request, Chat $chat): JsonResponse
    {
        $this->ensureModuleEnabled();

        $this->authorize('view', $chat);

        $validated = $request->validate([
            'funnel_id' => ['required', 'integer', 'exists:funnels,id'],
            'scope' => ['nullable', 'string', 'in:all,mine,department'],
            'assignee_id' => ['nullable', 'integer', 'exists:users,id'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'whatsapp_session_id' => ['nullable', 'integer', 'exists:whatsapp_sessions,id'],
            'search' => ['nullable', 'string', 'max:120'],
        ]);

        $user = $request->user();
        abort_unless($user !== null, 403);

        $filters = FunnelBoardFilters::fromRequest($validated);

        return response()->json([
            'card' => $this->boardService->cardForBroadcast($user, $chat, $filters),
        ]);
    }

    public function bulkMove(Request $request): JsonResponse
    {
        $this->ensureModuleEnabled();

        $validated = $request->validate([
            'funnel_id' => ['required', 'integer', 'exists:funnels,id'],
            'stage_id' => ['required', 'integer'],
            'chat_ids' => ['required', 'array', 'min:1', 'max:100'],
            'chat_ids.*' => ['integer', 'exists:chats,id'],
            'force_locked' => ['sometimes', 'boolean'],
        ]);

        $user = $request->user();
        abort_unless($user !== null, 403);

        $result = $this->bulkMoveService->move(
            $user,
            (int) $validated['funnel_id'],
            (int) $validated['stage_id'],
            array_map('intval', $validated['chat_ids']),
            (bool) ($validated['force_locked'] ?? false),
        );

        return response()->json([
            'success' => $result['moved'] > 0,
            ...$result,
        ]);
    }

    public function stageCards(Request $request): JsonResponse
    {
        $this->ensureModuleEnabled();

        $validated = $request->validate([
            'funnel_id' => ['required', 'integer', 'exists:funnels,id'],
            'stage_id' => ['required', 'integer'],
            'offset' => ['nullable', 'integer', 'min:0'],
            'scope' => ['nullable', 'string', 'in:all,mine,department'],
            'assignee_id' => ['nullable', 'integer', 'exists:users,id'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'whatsapp_session_id' => ['nullable', 'integer', 'exists:whatsapp_sessions,id'],
            'search' => ['nullable', 'string', 'max:120'],
        ]);

        $user = $request->user();
        abort_unless($user !== null, 403);

        $filters = FunnelBoardFilters::fromRequest($validated);
        $offset = (int) ($validated['offset'] ?? 0);

        return response()->json([
            'cards' => $this->boardService->stageCards(
                $user,
                (int) $validated['funnel_id'],
                (int) $validated['stage_id'],
                $filters,
                $offset,
            ),
        ]);
    }

    private function ensureModuleEnabled(): void
    {
        abort_unless(
            SystemSetting::getValue('module_funnels', 'on') === 'on',
            403,
            'Модуль «Воронки продаж» отключён администратором.',
        );
    }
}
