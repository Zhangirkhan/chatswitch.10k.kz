<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Events\UserTyping;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SendChatMessageRequest;
use App\Http\Requests\Api\V1\StoreChatRequest;
use App\Http\Resources\Api\V1\ChatResource;
use App\Http\Resources\Api\V1\MessageResource;
use App\Models\Chat;
use App\Models\ChatAssignment;
use App\Models\Contact;
use App\Models\User;
use App\Models\WhatsappSession;
use App\Services\Chat\ChatLeadClosureService;
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
        private readonly ChatLeadClosureService $leadClosureService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $perPage = (int) $request->query('per_page', 50);
        $perPage = min(100, max(1, $perPage));

        [$listOwnership, $filter] = $this->resolveInboxQuery($request);

        $chats = $this->chatService->getChatsForUser(
            $request->user(),
            $request->query('search'),
            $listOwnership,
            $filter,
        )
            ->where('is_archived', false)
            ->with(['funnelStage:id,funnel_id,name,color,position,stage_type'])
            ->paginate($perPage);

        return ChatResource::collection($chats)->response();
    }

    public function store(StoreChatRequest $request): JsonResponse
    {
        $user = $request->user();
        $contact = Contact::query()->findOrFail((int) $request->input('contact_id'));
        $session = $this->resolveWhatsappSessionForContact(
            $user,
            $contact,
            $request->filled('whatsapp_session_id') ? (int) $request->input('whatsapp_session_id') : null,
        );

        abort_unless($user->can('use', $session), 403, 'Этот номер WhatsApp вам не назначен.');

        $chat = $this->chatService->findOrCreateChatForContact($contact, $session);

        if ($chat->is_archived) {
            $chat->update(['is_archived' => false]);
        }

        if ($chat->is_lead_closed) {
            $chat = $this->leadClosureService->reopen($chat);
        }

        if (! $user->hasRole('administrator')) {
            ChatAssignment::firstOrCreate(
                ['chat_id' => $chat->id, 'user_id' => $user->id],
                ['assigned_by' => $user->id],
            );
        }

        $chat->load([
            'contact',
            'funnelStage:id,funnel_id,name,color,position,stage_type',
            'whatsappSession:id,session_name,display_name,display_color,phone_number,status',
            'assignments.user',
        ]);

        return ChatResource::make($chat)
            ->response()
            ->setStatusCode(201);
    }

    public function close(Request $request, Chat $chat): JsonResponse
    {
        $this->authorize('manage', $chat);

        $chat = $this->leadClosureService->close($chat);
        $chat->load([
            'contact',
            'funnelStage:id,funnel_id,name,color,position,stage_type',
            'whatsappSession:id,session_name,display_name,display_color,phone_number,status',
            'assignments.user',
        ]);

        return ChatResource::make($chat)->response();
    }

    public function reopen(Request $request, Chat $chat): JsonResponse
    {
        $this->authorize('manage', $chat);

        $chat = $this->leadClosureService->reopen($chat);
        $chat->load([
            'contact',
            'funnelStage:id,funnel_id,name,color,position,stage_type',
            'whatsappSession:id,session_name,display_name,display_color,phone_number,status',
            'assignments.user',
        ]);

        return ChatResource::make($chat)->response();
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
        $usesBefore = (is_string($beforeTs) ? trim($beforeTs) !== '' : $beforeTs !== null && $beforeTs !== '')
            || $beforeId > 0;
        $usesAfterId = $request->has('after_id')
            && $request->input('after_id') !== null
            && $request->input('after_id') !== '';

        if ($usesAfterId && $usesBefore) {
            return response()->json([
                'message' => 'Параметры after_id и before_id/before_timestamp нельзя комбинировать.',
            ], 400);
        }

        $query = $chat->messages()->with(OutboundChatMessageDispatcher::messageWithRelations());

        if ($usesAfterId) {
            $afterId = (int) $request->input('after_id');
            $messages = $query
                ->where('id', '>', $afterId)
                ->orderBy('id')
                ->limit($limit)
                ->get();

            return response()->json([
                'messages' => MessageResource::collection($messages),
            ]);
        }

        $messages = $query
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

    /**
     * @return array{0: string, 1: string|null}
     */
    private function resolveInboxQuery(Request $request): array
    {
        $raw = is_string($request->query('filter')) ? trim($request->query('filter')) : 'all';

        if ($raw === '' || $raw === 'all') {
            return ['all', null];
        }

        if ($raw === 'mine') {
            return ['mine', null];
        }

        if (in_array($raw, ['favorites', 'auto_reply', 'closed'], true)) {
            return ['all', $raw];
        }

        return ['all', null];
    }

    private function resolveWhatsappSessionForContact(
        User $user,
        Contact $contact,
        ?int $sessionId,
    ): WhatsappSession {
        if ($sessionId !== null) {
            return WhatsappSession::query()->findOrFail($sessionId);
        }

        $existingSessionId = Chat::query()
            ->where('contact_id', $contact->id)
            ->where('is_group', false)
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->value('whatsapp_session_id');

        if ($existingSessionId !== null) {
            return WhatsappSession::query()->findOrFail((int) $existingSessionId);
        }

        $sessionQuery = WhatsappSession::query()
            ->where('desired_state', WhatsappSession::DESIRED_ACTIVE)
            ->orderBy('id');

        if ($user->hasRole('administrator')) {
            $session = $sessionQuery->first();
            abort_if($session === null, 422, 'Нет доступных WhatsApp-сессий.');

            return $session;
        }

        $session = $user->whatsappSessions()
            ->where('desired_state', WhatsappSession::DESIRED_ACTIVE)
            ->orderBy('whatsapp_sessions.id')
            ->first();

        abort_if($session === null, 422, 'Нет доступных WhatsApp-сессий.');

        return $session;
    }
}
