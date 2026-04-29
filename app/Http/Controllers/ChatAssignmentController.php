<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Events\ChatsListNotify;
use App\Models\Chat;
use App\Models\ChatAssignment;
use App\Models\User;
use App\Services\ChatService;
use App\Support\ChatBroadcastAudience;
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

        $this->assertAdministratorAssigneeRules($request, $chat, [(int) $validated['user_id']]);

        $oldIds = $chat->assignments()->pluck('user_id')->all();

        ChatAssignment::firstOrCreate(
            ['chat_id' => $chat->id, 'user_id' => $validated['user_id']],
            ['assigned_by' => $request->user()->id],
        );

        $newIds = $chat->assignments()->pluck('user_id')->all();
        $this->chatService->logAssignmentChange($chat, $request->user(), $oldIds, $newIds);
        $this->broadcastAssignmentAdded($chat, $oldIds, $newIds);

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
        $this->broadcastAssignmentAdded($chat, $oldIds, $newIds);

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
        $this->assertAdministratorAssigneeRules($request, $chat, $userIds);

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
        $this->broadcastAssignmentAdded($chat, $oldIds, $newIds);

        return response()->json([
            'success' => true,
            'assignments' => $chat->assignments()->with('user')->get(),
        ]);
    }

    /**
     * У администратора к чату должны быть прикреплены отделы; новых ответственных можно брать только из этих отделов
     * (уже закреплённые ранее сохраняются, даже если отдел в профиле не совпадает).
     *
     * @param  list<int>  $userIds
     */
    /**
     * @param  list<int|string>  $oldIds
     * @param  list<int|string>  $newIds
     */
    private function broadcastAssignmentAdded(Chat $chat, array $oldIds, array $newIds): void
    {
        $old = array_map(intval(...), $oldIds);
        $new = array_map(intval(...), $newIds);
        $added = array_values(array_diff($new, $old));
        if ($added === []) {
            return;
        }

        $chat->loadMissing(['contact', 'whatsappSession']);
        $name = ChatBroadcastAudience::chatDisplayName($chat);
        $icon = ChatBroadcastAudience::absoluteIconUrl($chat->contact?->profile_picture_url);

        broadcast(new ChatsListNotify(
            $chat->id,
            'assignment',
            'Назначение',
            'Вас назначили ответственным за «'.$name.'»',
            $icon,
            (bool) $chat->is_muted,
            $added,
        ));
    }

    private function assertAdministratorAssigneeRules(Request $request, Chat $chat, array $userIds): void
    {
        $actor = $request->user();
        if ($actor === null || ! $actor->hasRole('administrator')) {
            return;
        }

        $departmentIds = $chat->departments()->pluck('departments.id')->all();
        if ($departmentIds === []) {
            abort(422, 'Сначала прикрепите к чату хотя бы один отдел, затем назначайте сотрудников.');
        }

        $allowedNew = User::query()
            ->where('is_active', true)
            ->whereIn('department_id', $departmentIds)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $oldIds = $chat->assignments()->pluck('user_id')->map(fn ($id): int => (int) $id)->all();

        foreach ($userIds as $rawId) {
            $id = (int) $rawId;
            if (in_array($id, $oldIds, true)) {
                continue;
            }
            if (! in_array($id, $allowedNew, true)) {
                abort(422, 'Назначать можно только сотрудников из отделов, прикреплённых к этому чату.');
            }
        }
    }
}
