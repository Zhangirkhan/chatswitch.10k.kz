<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Chat;
use App\Models\ChatAssignment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ChatAssignmentController extends Controller
{
    public function store(Request $request, Chat $chat): JsonResponse
    {
        $request->validate([
            'user_id' => 'required|exists:users,id',
        ]);

        ChatAssignment::firstOrCreate(
            ['chat_id' => $chat->id, 'user_id' => $request->input('user_id')],
            ['assigned_by' => $request->user()->id],
        );

        return response()->json([
            'success' => true,
            'assignments' => $chat->assignments()->with('user')->get(),
        ]);
    }

    public function destroy(Chat $chat, ChatAssignment $assignment): JsonResponse
    {
        $assignment->delete();

        return response()->json([
            'success' => true,
            'assignments' => $chat->assignments()->with('user')->get(),
        ]);
    }
}
