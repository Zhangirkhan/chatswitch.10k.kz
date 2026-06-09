<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Events\ChatsListNotify;
use App\Jobs\AnalyzeCompanyToneProfileJob;
use App\Jobs\AnalyzeEmployeeToneProfileJob;
use App\Models\Chat;
use App\Models\ChatAssignment;
use App\Models\Message;
use App\Models\User;
use App\Services\Chat\ChatAssignmentAssigneeGuard;
use App\Services\Calendar\ChatAssignmentCalendarSyncService;
use App\Services\ChatService;
use App\Services\Push\MobilePushService;
use App\Support\ChatBroadcastAudience;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ChatAssignmentController extends Controller
{
    public function __construct(
        private readonly ChatService $chatService,
        private readonly ChatAssignmentCalendarSyncService $assignmentCalendarSync,
        private readonly ChatAssignmentAssigneeGuard $assigneeGuard,
    ) {}

    public function store(Request $request, Chat $chat): JsonResponse
    {
        $this->authorize('assign', $chat);

        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
        ]);

        $this->assigneeGuard->assertAssignable($request->user(), $chat, [(int) $validated['user_id']]);

        $oldIds = $chat->assignments()->pluck('user_id')->all();

        ChatAssignment::firstOrCreate(
            ['chat_id' => $chat->id, 'user_id' => $validated['user_id']],
            ['assigned_by' => $request->user()->id],
        );

        $newIds = $chat->assignments()->pluck('user_id')->all();
        $this->assignmentCalendarSync->syncFromAssignmentChange($chat, $oldIds, $newIds);
        $this->syncAiResponder($chat, $newIds);
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
        $this->assignmentCalendarSync->syncFromAssignmentChange($chat, $oldIds, $newIds);
        $this->syncAiResponder($chat, $newIds);
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
        $this->assigneeGuard->assertAssignable($request->user(), $chat, array_map(intval(...), $userIds));
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
        $this->assignmentCalendarSync->syncFromAssignmentChange($chat, $oldIds, $newIds);
        $this->syncAiResponder($chat, $newIds);
        $this->chatService->logAssignmentChange($chat, $request->user(), $oldIds, $newIds);
        $this->broadcastAssignmentAdded($chat, $oldIds, $newIds);

        return response()->json([
            'success' => true,
            'assignments' => $chat->assignments()->with('user')->get(),
        ]);
    }

    public function history(Request $request, Chat $chat): JsonResponse
    {
        $this->authorize('view', $chat);

        $history = $chat->messages()
            ->where('direction', 'system')
            ->where('body', 'like', 'Ответственные за чат обновлены:%')
            ->orderByRaw('COALESCE(message_timestamp, created_at) DESC')
            ->orderByDesc('id')
            ->limit(100)
            ->get(['id', 'body', 'message_timestamp', 'created_at'])
            ->map(fn ($message) => [
                'id' => $message->id,
                'body' => $message->body,
                'at' => ($message->message_timestamp ?: $message->created_at)?->toIso8601String(),
            ])
            ->values();

        $current = $chat->assignments()
            ->with(['user:id,name,email', 'assignedByUser:id,name'])
            ->orderBy('created_at')
            ->get()
            ->map(fn (ChatAssignment $assignment) => [
                'id' => $assignment->id,
                'user_id' => $assignment->user_id,
                'user_name' => $assignment->user?->name,
                'assigned_by_name' => $assignment->assignedByUser?->name,
                'assigned_at' => $assignment->created_at?->toIso8601String(),
            ])
            ->values();

        return response()->json([
            'current' => $current,
            'history' => $history,
        ]);
    }

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

        app(MobilePushService::class)->notifyChatAssigned($chat, $added);
    }

    /**
     * @param  list<int|string>  $assignedUserIds
     */
    private function syncAiResponder(Chat $chat, array $assignedUserIds): void
    {
        if (! $chat->ai_enabled) {
            return;
        }

        $assigned = array_values(array_unique(array_map(intval(...), $assignedUserIds)));
        if ($assigned === []) {
            $chat->forceFill(['ai_responder_user_id' => null])->save();

            return;
        }

        if ($chat->ai_responder_user_id !== null && in_array((int) $chat->ai_responder_user_id, $assigned, true)) {
            return;
        }

        $responderId = $this->preferredResponderId($chat, $assigned);
        $chat->forceFill(['ai_responder_user_id' => $responderId])->save();

        if ($responderId !== null && $chat->company_id !== null) {
            AnalyzeCompanyToneProfileJob::dispatch((int) $chat->company_id);
            AnalyzeEmployeeToneProfileJob::dispatch($responderId, (int) $chat->company_id, (int) $chat->id);
        }
    }

    /**
     * @param  list<int>  $assignedUserIds
     */
    private function preferredResponderId(Chat $chat, array $assignedUserIds): ?int
    {
        $lastOutboundAssignedId = Message::query()
            ->where('chat_id', $chat->id)
            ->where('direction', 'outbound')
            ->whereIn('sent_by_user_id', $assignedUserIds)
            ->orderByDesc('message_timestamp')
            ->orderByDesc('id')
            ->value('sent_by_user_id');

        if ($lastOutboundAssignedId !== null) {
            return (int) $lastOutboundAssignedId;
        }

        $nonAdminId = User::query()
            ->whereIn('id', $assignedUserIds)
            ->whereDoesntHave('roles', fn ($query) => $query->where('name', 'administrator'))
            ->orderBy('name')
            ->value('id');

        return $nonAdminId !== null ? (int) $nonAdminId : ($assignedUserIds[0] ?? null);
    }
}
