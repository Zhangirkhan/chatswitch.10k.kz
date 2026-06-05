<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Events\UserTyping;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SendChatMessageRequest;
use App\Http\Resources\Api\V1\ChatResource;
use App\Http\Resources\Api\V1\MessageResource;
use App\Models\Chat;
use App\Services\ChatService;
use App\Services\OutboundChatMessageDispatcher;
use App\Services\WhatsappService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ChatController extends Controller
{
    public function __construct(
        private readonly ChatService $chatService,
        private readonly WhatsappService $whatsappService,
        private readonly OutboundChatMessageDispatcher $outboundDispatcher,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $perPage = (int) $request->query('per_page', 50);
        $perPage = min(100, max(1, $perPage));

        $chats = $this->chatService->getChatsForUser($request->user(), $request->query('search'))
            ->where('is_archived', false)
            ->with(['funnelStage:id,funnel_id,name,color,position,stage_type'])
            ->paginate($perPage);

        return ChatResource::collection($chats)->response();
    }

    public function archivedIndex(Request $request): JsonResponse
    {
        $perPage = (int) $request->query('per_page', 50);
        $perPage = min(100, max(1, $perPage));

        $chats = $this->chatService->getChatsForUser($request->user(), $request->query('search'))
            ->where('is_archived', true)
            ->with(['funnelStage:id,funnel_id,name,color,position,stage_type'])
            ->paginate($perPage);

        return ChatResource::collection($chats)->response();
    }

    public function show(Request $request, Chat $chat): JsonResponse
    {
        $this->authorize('view', $chat);

        $chat->load([
            'contact',
            'funnel:id,name,color',
            'funnelStage:id,funnel_id,name,color,stage_type,position',
            'whatsappSession:id,session_name,display_name,display_color,phone_number,status',
            'assignments.user',
            'departments',
            'pinnedMessage' => function ($q): void {
                $q->select([
                    'id',
                    'chat_id',
                    'direction',
                    'type',
                    'body',
                    'sender_name',
                    'sender_phone',
                    'sent_by_user_id',
                    'message_timestamp',
                ])->with([
                    'sentByUser:id,name',
                ]);
            },
        ]);

        return ChatResource::withFunnelDetails($chat)->response();
    }

    public function messages(Request $request, Chat $chat): JsonResponse
    {
        $this->authorize('view', $chat);

        $limit = (int) $request->input('limit', 50);
        $limit = min(100, max(1, $limit));

        $beforeTs = $request->input('before_timestamp');
        $beforeId = (int) $request->input('before_id', 0);

        $messages = $chat->messages()
            ->with(OutboundChatMessageDispatcher::messageWithRelations())
            ->when(
                is_string($beforeTs) ? trim($beforeTs) !== '' : $beforeTs !== null && $beforeTs !== '',
                function ($q) use ($beforeTs, $beforeId): void {
                    if ($beforeId > 0) {
                        $q->whereRaw(
                            '(COALESCE(message_timestamp, created_at) < ?) OR (COALESCE(message_timestamp, created_at) = ? AND id < ?)',
                            [$beforeTs, $beforeTs, $beforeId],
                        );

                        return;
                    }
                    $q->whereRaw('COALESCE(message_timestamp, created_at) < ?', [$beforeTs]);
                },
            )
            ->orderByRaw('COALESCE(message_timestamp, created_at) DESC')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->reverse()
            ->values();

        return response()->json([
            'messages' => MessageResource::collection($messages),
        ]);
    }

    public function storeMessage(SendChatMessageRequest $request, Chat $chat): JsonResponse
    {
        $result = $this->outboundDispatcher->sendTextMessage(
            $request->user(),
            $chat,
            array_merge($request->validated(), [
                'mentions' => $request->input('mentions'),
                'mentions_meta' => $request->input('mentions_meta'),
            ]),
        );

        return response()->json([
            'message' => new MessageResource($result->message),
            'tone_profile_learning_scheduled' => $result->toneProfileLearningScheduled,
            'draft_edit_kind' => $result->draftEditKind,
        ]);
    }

    public function markRead(Request $request, Chat $chat): JsonResponse
    {
        $this->authorize('view', $chat);

        $chat->update(['unread_count' => 0]);
        $chat->load('whatsappSession');
        $this->whatsappService->sendSeen(
            $chat->whatsappSession->session_name,
            $chat->whatsapp_chat_id,
        );

        return response()->json(['success' => true]);
    }

    public function typing(Request $request, Chat $chat): JsonResponse
    {
        $this->authorize('view', $chat);

        $user = $request->user();
        broadcast(new UserTyping($chat->id, $user->id, $user->name));

        $chat->load('whatsappSession');
        $this->whatsappService->setTyping(
            $chat->whatsappSession->session_name,
            $chat->whatsapp_chat_id,
            true,
        );

        return response()->json(['success' => true]);
    }
}
