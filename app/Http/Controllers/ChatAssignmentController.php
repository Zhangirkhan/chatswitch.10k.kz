<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Chat;
use App\Models\ChatAssignment;
use App\Services\ChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ChatAssignmentController extends Controller
{
    public function __construct(
        private readonly ChatService $chatService,
    ) {}

    public function store(Request $request, Chat $chat): JsonResponse
    {
        $this->authorize('assign', $chat);

        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
        ]);

        $oldIds = $chat->assignments()->pluck('user_id')->all();

        ChatAssignment::firstOrCreate(
            ['chat_id' => $chat->id, 'user_id' => $validated['user_id']],
            ['assigned_by' => $request->user()->id],
        );

        $newIds = $chat->assignments()->pluck('user_id')->all();
        $this->chatService->logAssignmentChange($chat, $request->user(), $oldIds, $newIds);

        return response()->json([
            'success' => true,
            'assignments' => $chat->assignments()->with('user')->get(),
        ]);
    }

    public function destroy(Request $request, Chat $chat, ChatAssignment $assignment): JsonResponse
    {
        $this->authorize('assign', $chat);

        $oldIds = $chat->assignments()->pluck('user_id')->all();

        $assignment->delete();

        $newIds = $chat->assignments()->pluck('user_id')->all();
        $this->chatService->logAssignmentChange($chat, $request->user(), $oldIds, $newIds);

        return response()->json([
            'success' => true,
            'assignments' => $chat->assignments()->with('user')->get(),
        ]);
    }

    public function sync(Request $request, Chat $chat): JsonResponse
    {
        $this->authorize('assign', $chat);

        $validated = $request->validate([
            'user_ids' => ['array'],
            'user_ids.*' => ['integer', 'exists:users,id'],
        ]);

        $userIds = collect($validated['user_ids'] ?? [])->unique()->values()->all();
        $actorId = $request->user()->id;

        $oldIds = $chat->assignments()->pluck('user_id')->all();

        $chat->assignments()->whereNotIn('user_id', $userIds)->delete();

        $existing = $chat->assignments()->pluck('user_id')->all();
        foreach (array_values(array_diff($userIds, $existing)) as $userId) {
            ChatAssignment::firstOrCreate(
                ['chat_id' => $chat->id, 'user_id' => $userId],
                ['assigned_by' => $actorId],
            );
        }

        $newIds = $chat->assignments()->pluck('user_id')->all();
        $this->chatService->logAssignmentChange($chat, $request->user(), $oldIds, $newIds);

        return response()->json([
            'success' => true,
            'assignments' => $chat->assignments()->with('user')->get(),
        ]);
    }
}
