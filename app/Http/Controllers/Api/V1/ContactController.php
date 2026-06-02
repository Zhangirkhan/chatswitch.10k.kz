<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Contact\ClientsListService;
use App\Support\ContactListFilters;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ContactController extends Controller
{
    public function __construct(
        private readonly ClientsListService $clientsListService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user !== null, 403);

        $search = trim((string) $request->input('search', ''));
        $listFilters = ContactListFilters::fromRequest($request);
        $page = max(1, (int) $request->input('page', 1));
        $perPage = min(50, max(1, (int) $request->input('per_page', 20)));

        $paginator = $this->clientsListService->paginate(
            $user,
            $search,
            $listFilters,
            $page,
            $perPage,
            'page',
        );

        return response()->json([
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }
}
