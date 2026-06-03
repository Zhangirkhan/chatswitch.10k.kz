<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Events\TeamMessageReactionsUpdated;
use App\Events\TeamRoomPinUpdated;
use App\Events\TeamUserTyping;
use App\Models\Department;
use App\Models\DepartmentPost;
use App\Models\SystemSetting;
use App\Models\TeamConversation;
use App\Models\TeamMessage;
use App\Models\TeamMessageAttachment;
use App\Models\TeamMessageReaction;
use App\Models\User;
use App\Services\CrossChannelMessageShareService;
use App\Services\TeamChatService;
use App\Services\TeamDepartmentChatSyncService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class OrganizationTeamChatController extends Controller
{
    public function __construct(
        private readonly TeamChatService $teamChatService,
        private readonly TeamDepartmentChatSyncService $teamDepartmentChatSync,
        private readonly CrossChannelMessageShareService $crossChannelShare,
    ) {}

    public function index(Request $request): Response
    {
        $this->ensureModuleEnabled();
        $this->prepareTeamChatAccess($request->user());

        return Inertia::render('Organization/TeamChat/Index', [
            'departments' => $this->departmentsPayload($request->user()),
            'selectedConversationId' => null,
        ]);
    }

    public function show(Request $request, TeamConversation $teamConversation): Response
    {
        $this->ensureModuleEnabled();
        $this->authorizeTeamConversationView($request->user(), $teamConversation);

        $user = $request->user();

        return Inertia::render('Organization/TeamChat/Index', [
            'departments' => $this->departmentsPayload($user),
            'selectedConversationId' => $teamConversation->id,
            'conversationHeader' => $this->conversationHeaderPayload($user, $teamConversation),
        ]);
    }

    public function conversations(Request $request): JsonResponse
    {
        $this->ensureModuleEnabled();
        $user = $request->user();
        $this->prepareTeamChatAccess($user);

        $filter = $request->query('filter', '');
        $filter = is_string($filter) ? trim($filter) : '';
        if ($filter !== '' && ! in_array($filter, ['unread', 'department', 'direct'], true)) {
            $filter = '';
        }

        $conversations = $user->teamConversations()
            ->with(['department:id,name', 'userLow:id,name', 'userHigh:id,name'])
            ->withPivot('pinned_at')
            ->orderByRaw('CASE WHEN team_conversation_user.pinned_at IS NULL THEN 1 ELSE 0 END ASC')
            ->orderByDesc('team_conversation_user.pinned_at')
            ->orderByDesc('team_conversations.last_message_at')
            ->orderByDesc('team_conversations.id')
            ->get();

        $conversations = $this->filterConversationsVisibleToUser($conversations, $user);

        $ids = $conversations->pluck('id')->all();
        $unreadMap = $this->unreadCountsForUser($user, $ids);

        if ($filter === 'department') {
            $conversations = $conversations->where('type', TeamConversation::TYPE_DEPARTMENT)->values();
        } elseif ($filter === 'direct') {
            $conversations = $conversations->where('type', TeamConversation::TYPE_DIRECT)->values();
        } elseif ($filter === 'unread') {
            $conversations = $conversations->filter(function (TeamConversation $c) use ($unreadMap): bool {
                return ((int) ($unreadMap[$c->id] ?? 0)) > 0;
            })->values();
        }

        $conversations = $this->sortConversationsForSidebar($conversations);

        $items = $conversations->map(fn (TeamConversation $c) => $this->transformConversationListItem($user, $c, (int) ($unreadMap[$c->id] ?? 0)));

        return response()->json([
            'conversations' => $items,
            'filter' => $filter === '' ? null : $filter,
        ]);
    }

    public function setPinned(Request $request, TeamConversation $teamConversation): JsonResponse
    {
        $this->ensureModuleEnabled();
        $this->authorizeTeamConversationView($request->user(), $teamConversation);

        $data = $request->validate([
            'pinned' => ['required', 'boolean'],
        ]);

        $user = $request->user();
        $teamConversation->participants()->updateExistingPivot($user->id, [
            'pinned_at' => $data['pinned'] ? now() : null,
            'updated_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'pinned' => (bool) $data['pinned'],
        ]);
    }

    public function setRoomPinnedMessage(Request $request, TeamConversation $teamConversation): JsonResponse
    {
        $this->ensureModuleEnabled();
        $this->authorize('pinRoomMessage', $teamConversation);

        $data = $request->validate([
            'team_message_id' => ['present', 'nullable', 'integer', 'min:1'],
        ]);

        $mid = $data['team_message_id'] !== null ? (int) $data['team_message_id'] : 0;

        if ($mid < 1) {
            $teamConversation->pinned_team_message_id = null;
            $teamConversation->save();
            broadcast(new TeamRoomPinUpdated($teamConversation->id, null));

            return response()->json([
                'room_pinned_message' => null,
            ]);
        }

        $message = TeamMessage::query()
            ->where('team_conversation_id', $teamConversation->id)
            ->whereKey($mid)
            ->first();

        if ($message === null) {
            throw ValidationException::withMessages([
                'team_message_id' => 'Сообщение не найдено в этой беседе.',
            ]);
        }

        $teamConversation->pinned_team_message_id = $message->id;
        $teamConversation->save();
        $message->loadMissing(['sender:id,name']);
        $payload = $this->roomPinnedMessagePayload($message);
        broadcast(new TeamRoomPinUpdated($teamConversation->id, $payload));

        return response()->json([
            'room_pinned_message' => $payload,
        ]);
    }

    public function contacts(Request $request): JsonResponse
    {
        $this->ensureModuleEnabled();
        $user = $request->user();
        $companyId = $user->company_id;
        if ($companyId === null) {
            return response()->json(['contacts' => []]);
        }

        $search = trim((string) $request->query('search', ''));
        $query = User::query()
            ->where('is_active', true)
            ->where('company_id', $companyId)
            ->where('id', '!=', $user->id)
            ->orderBy('name');

        if ($search !== '') {
            $query->where(function (Builder $q) use ($search): void {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $contacts = $query->limit(200)->get(['id', 'name', 'email'])->values();

        return response()->json(['contacts' => $contacts]);
    }

    public function search(Request $request): JsonResponse
    {
        $this->ensureModuleEnabled();
        $user = $request->user();
        $this->prepareTeamChatAccess($user);
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
        ]);
        $q = trim((string) ($data['q'] ?? ''));
        if (mb_strlen($q) < 2) {
            return response()->json([
                'query' => $q,
                'conversations' => [],
                'messages' => [],
                'colleagues' => [],
            ]);
        }

        $like = '%'.$this->escapeLike($q).'%';

        $conversationIds = $this->filterConversationsVisibleToUser(
            $user->teamConversations()->get(),
            $user,
        )->pluck('id')->all();
        if ($conversationIds === []) {
            return response()->json([
                'query' => $q,
                'conversations' => [],
                'messages' => [],
                'colleagues' => $this->searchColleagueRows($user, $like),
            ]);
        }

        $conversations = $user->teamConversations()
            ->with(['department:id,name', 'userLow:id,name', 'userHigh:id,name'])
            ->withPivot('pinned_at')
            ->where(function (Builder $outer) use ($like): void {
                $outer->where('team_conversations.last_message_preview', 'like', $like)
                    ->orWhereHas('department', function (Builder $d) use ($like): void {
                        $d->where('name', 'like', $like);
                    })
                    ->orWhere(function (Builder $dr) use ($like): void {
                        $dr->where('team_conversations.type', TeamConversation::TYPE_DIRECT)
                            ->where(function (Builder $d2) use ($like): void {
                                $d2->whereHas('userLow', fn (Builder $u) => $u->where('name', 'like', $like))
                                    ->orWhereHas('userHigh', fn (Builder $u) => $u->where('name', 'like', $like));
                            });
                    });
            })
            ->orderByRaw('CASE WHEN team_conversation_user.pinned_at IS NULL THEN 1 ELSE 0 END ASC')
            ->orderByDesc('team_conversation_user.pinned_at')
            ->orderByDesc('team_conversations.last_message_at')
            ->orderByDesc('team_conversations.id')
            ->limit(20)
            ->get();

        $conversations = $this->filterConversationsVisibleToUser($conversations, $user);
        $conversations = $this->sortConversationsForSidebar($conversations);

        $conversationRows = $conversations->map(function (TeamConversation $c) use ($user): array {
            $row = $this->transformConversationListItem($user, $c, 0);

            return [
                'id' => $row['id'],
                'type' => $row['type'],
                'title' => $row['title'],
                'subtitle' => $row['subtitle'],
                'last_message_preview' => $row['last_message_preview'],
            ];
        })->values()->all();

        $messages = TeamMessage::query()
            ->whereIn('team_conversation_id', $conversationIds)
            ->whereNull('deleted_at')
            ->where(function (Builder $mq) use ($like): void {
                $mq->where('body', 'like', $like)
                    ->orWhere('forward_quote_body', 'like', $like)
                    ->orWhere('forward_source_title', 'like', $like);
            })
            ->with(['sender:id,name'])
            ->orderByDesc('id')
            ->limit(25)
            ->get();

        $msgConvIds = $messages->pluck('team_conversation_id')->unique()->filter()->values()->all();
        $convMap = $msgConvIds === []
            ? collect()
            : TeamConversation::query()
                ->whereIn('id', $msgConvIds)
                ->with(['department:id,name', 'userLow:id,name', 'userHigh:id,name'])
                ->get()
                ->keyBy(fn (TeamConversation $c) => $c->id);

        $messageRows = $messages->map(function (TeamMessage $m) use ($user, $convMap): array {
            $c = $convMap->get($m->team_conversation_id);
            $title = $c instanceof TeamConversation
                ? $this->transformConversationListItem($user, $c, 0)['title']
                : 'Чат';

            $snippetSource = trim((string) $m->body);
            if ($snippetSource === '' && is_string($m->forward_quote_body) && trim($m->forward_quote_body) !== '') {
                $snippetSource = trim($m->forward_quote_body);
            }

            return [
                'id' => $m->id,
                'team_conversation_id' => $m->team_conversation_id,
                'conversation_title' => $title,
                'body_snippet' => mb_substr(preg_replace('/\s+/u', ' ', $snippetSource) ?? '', 0, 160),
                'created_at' => $m->created_at?->toIso8601String(),
                'sender_name' => $m->sender?->name,
            ];
        })->values()->all();

        $colleagueRows = $this->searchColleagueRows($user, $like);

        return response()->json([
            'query' => $q,
            'conversations' => $conversationRows,
            'messages' => $messageRows,
            'colleagues' => $colleagueRows,
        ]);
    }

    public function openDirect(Request $request): JsonResponse
    {
        $this->ensureModuleEnabled();
        $user = $request->user();
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
        ]);
        $peer = User::query()->findOrFail((int) $data['user_id']);
        $direct = $this->teamChatService->findOrCreateDirect($user, $peer);
        $conversation = $user->teamConversations()
            ->where('team_conversations.id', $direct->id)
            ->with(['department:id,name', 'userLow:id,name', 'userHigh:id,name'])
            ->withPivot('pinned_at')
            ->firstOrFail();
        $unreadMap = $this->unreadCountsForUser($user, [$conversation->id]);

        return response()->json([
            'conversation' => $this->transformConversationListItem(
                $user,
                $conversation,
                (int) ($unreadMap[$conversation->id] ?? 0),
            ),
        ]);
    }

    public function readMeta(Request $request, TeamConversation $teamConversation): JsonResponse
    {
        $this->ensureModuleEnabled();
        $this->authorizeTeamConversationView($request->user(), $teamConversation);

        return response()->json([
            'read_meta' => $this->readMetaPayload($request->user(), $teamConversation),
        ]);
    }

    public function participants(Request $request, TeamConversation $teamConversation): JsonResponse
    {
        $this->ensureModuleEnabled();
        $this->authorizeTeamConversationView($request->user(), $teamConversation);

        $participants = $teamConversation->participants()
            ->where('users.is_active', true)
            ->orderBy('name')
            ->limit(500)
            ->get(['users.id', 'users.name'])
            ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name])
            ->values();

        return response()->json(['participants' => $participants]);
    }

    public function messages(Request $request, TeamConversation $teamConversation): JsonResponse
    {
        $this->ensureModuleEnabled();
        $this->authorizeTeamConversationView($request->user(), $teamConversation);

        $teamConversation->loadMissing(['pinnedMessage.sender:id,name']);

        $beforeId = $request->integer('before_id') ?: null;
        $query = TeamMessage::query()
            ->where('team_conversation_id', $teamConversation->id)
            ->with(['sender:id,name', 'parentMessage.sender:id,name', 'attachments', 'reactions.user:id,name'])
            ->orderByDesc('id');

        if ($beforeId !== null) {
            $query->where('id', '<', $beforeId);
        }

        $page = $query->limit(50)->get()->reverse()->values();

        $mentionIds = $page
            ->flatMap(fn (TeamMessage $m) => collect($m->mentioned_user_ids ?? []))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->filter(fn (int $id) => $id > 0)
            ->values()
            ->all();

        $mentionNamesById = $mentionIds === []
            ? []
            : User::query()->whereIn('id', $mentionIds)->pluck('name', 'id')->all();

        $items = $page->map(fn (TeamMessage $m) => $this->transformMessage($m, $mentionNamesById));

        $user = $request->user();
        $header = $this->conversationHeaderPayload($user, $teamConversation);

        return response()->json([
            'messages' => $items,
            'conversation' => [
                'id' => $teamConversation->id,
                'type' => $teamConversation->type,
                'title' => $header['title'],
                'subtitle' => $header['subtitle'],
                'is_pinned' => $header['is_pinned'],
                'department_id' => $teamConversation->isDepartment() ? $teamConversation->department_id : null,
                'can_pin_room_message' => $user->can('pinRoomMessage', $teamConversation),
                'room_pinned_message' => $this->roomPinnedMessagePayload($teamConversation->pinnedMessage),
            ],
            'read_meta' => $this->readMetaPayload($user, $teamConversation),
        ]);
    }

    public function storeMessage(Request $request, TeamConversation $teamConversation): JsonResponse
    {
        $this->ensureModuleEnabled();
        $this->authorizeTeamConversationView($request->user(), $teamConversation);

        $data = $request->validate([
            'body' => ['nullable', 'string', 'max:16000'],
            'client_message_id' => ['sometimes', 'nullable', 'string', 'uuid', 'max:64'],
            'mention_user_ids' => ['sometimes', 'array', 'max:20'],
            'mention_user_ids.*' => ['integer', 'distinct', 'min:1'],
            'forwarded_from_team_message_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'parent_team_message_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'attachments' => ['sometimes', 'array', 'max:5'],
            'attachments.*' => ['file', 'max:15360'],
        ]);

        $rawFiles = $request->file('attachments');
        $uploads = [];
        if ($rawFiles instanceof UploadedFile) {
            $uploads = [$rawFiles];
        } elseif (is_array($rawFiles)) {
            foreach ($rawFiles as $f) {
                if ($f instanceof UploadedFile) {
                    $uploads[] = $f;
                }
            }
        }

        $forwardSourceId = isset($data['forwarded_from_team_message_id'])
            ? (int) $data['forwarded_from_team_message_id']
            : 0;
        $bodyTrim = trim((string) ($data['body'] ?? ''));

        if ($forwardSourceId > 0) {
            if ($uploads !== []) {
                throw ValidationException::withMessages([
                    'attachments' => 'Вложения недоступны при пересылке.',
                ]);
            }

            if (($data['mention_user_ids'] ?? null) !== null && $data['mention_user_ids'] !== []) {
                throw ValidationException::withMessages([
                    'mention_user_ids' => 'Упоминания недоступны при пересылке.',
                ]);
            }

            if (($data['parent_team_message_id'] ?? null) !== null && (int) $data['parent_team_message_id'] > 0) {
                throw ValidationException::withMessages([
                    'parent_team_message_id' => 'Ответ недоступен при пересылке.',
                ]);
            }

            $result = $this->teamChatService->forwardMessage(
                $request->user(),
                $teamConversation,
                $forwardSourceId,
                $bodyTrim,
                $data['client_message_id'] ?? null,
            );
        } else {
            if ($bodyTrim === '' && $uploads === []) {
                throw ValidationException::withMessages([
                    'body' => 'Сообщение не может быть пустым.',
                ]);
            }

            $result = $this->teamChatService->sendMessage(
                $request->user(),
                $teamConversation,
                $bodyTrim,
                $data['client_message_id'] ?? null,
                $data['mention_user_ids'] ?? null,
                isset($data['parent_team_message_id']) ? (int) $data['parent_team_message_id'] : null,
                $uploads,
                $request,
            );
        }
        $message = $result->message;
        $message->load(['sender:id,name', 'parentMessage.sender:id,name', 'attachments', 'reactions.user:id,name']);
        if (! $result->duplicate) {
            $teamConversation->refresh();
        }

        $mentionIds = $message->mentioned_user_ids ?? [];
        $mentionNamesById = $mentionIds === [] || $mentionIds === null
            ? []
            : User::query()->whereIn('id', $mentionIds)->pluck('name', 'id')->all();

        return response()->json([
            'message' => $this->transformMessage($message, $mentionNamesById),
            'duplicate' => $result->duplicate,
            'conversation' => [
                'id' => $teamConversation->id,
                'last_message_at' => $teamConversation->fresh()->last_message_at?->toIso8601String(),
                'last_message_preview' => $teamConversation->fresh()->last_message_preview,
            ],
        ]);
    }

    public function shareToClients(Request $request, TeamMessage $teamMessage): JsonResponse
    {
        $this->ensureModuleEnabled();

        $data = $request->validate([
            'contact_ids' => ['required', 'array', 'min:1'],
            'contact_ids.*' => ['integer', 'exists:contacts,id'],
            'whatsapp_session_id' => ['required', 'integer', 'exists:whatsapp_sessions,id'],
            'body' => ['nullable', 'string', 'max:16000'],
        ]);

        $sent = $this->crossChannelShare->shareTeamMessageToClients(
            $request->user(),
            $teamMessage,
            array_map('intval', $data['contact_ids']),
            (int) $data['whatsapp_session_id'],
            trim((string) ($data['body'] ?? '')),
        );

        return response()->json(['success' => true, 'sent' => $sent]);
    }

    public function markRead(Request $request, TeamConversation $teamConversation): JsonResponse
    {
        $this->ensureModuleEnabled();
        $this->authorizeTeamConversationView($request->user(), $teamConversation);

        $data = $request->validate([
            'message_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ]);

        $this->teamChatService->markRead(
            $request->user(),
            $teamConversation,
            isset($data['message_id']) ? (int) $data['message_id'] : null,
        );

        return response()->json(['success' => true]);
    }

    public function markDelivered(Request $request, TeamConversation $teamConversation): JsonResponse
    {
        $this->ensureModuleEnabled();
        $this->authorizeTeamConversationView($request->user(), $teamConversation);

        $data = $request->validate([
            'message_id' => ['required', 'integer', 'min:1'],
        ]);

        $this->teamChatService->markDelivered(
            $request->user(),
            $teamConversation,
            (int) $data['message_id'],
        );

        return response()->json(['success' => true]);
    }

    public function typing(Request $request, TeamConversation $teamConversation): JsonResponse
    {
        $this->ensureModuleEnabled();
        $this->authorizeTeamConversationView($request->user(), $teamConversation);

        $user = $request->user();
        $key = 'team-typing:'.$user->id.':'.$teamConversation->id;
        if (RateLimiter::tooManyAttempts($key, 1)) {
            return response()->json(['success' => true]);
        }
        RateLimiter::hit($key, 2);

        broadcast(new TeamUserTyping(
            $teamConversation->id,
            $user->id,
            (string) $user->name,
        ));

        return response()->json(['success' => true]);
    }

    public function reactToMessage(Request $request, TeamConversation $teamConversation, TeamMessage $teamMessage): JsonResponse
    {
        $this->ensureModuleEnabled();
        $this->authorizeTeamConversationView($request->user(), $teamConversation);
        abort_unless((int) $teamMessage->team_conversation_id === (int) $teamConversation->id, 404);

        $data = $request->validate([
            'emoji' => ['required', 'string', 'max:32'],
        ]);

        $emoji = $data['emoji'];
        $normalizedNew = $this->normalizeTeamReactionEmoji($emoji);
        $user = $request->user();

        $existing = TeamMessageReaction::query()
            ->where('team_message_id', $teamMessage->id)
            ->where('user_id', $user->id)
            ->first();

        if ($existing !== null && $this->normalizeTeamReactionEmoji((string) $existing->emoji) === $normalizedNew) {
            $existing->delete();
        } else {
            TeamMessageReaction::query()->updateOrCreate(
                [
                    'team_message_id' => $teamMessage->id,
                    'user_id' => $user->id,
                ],
                ['emoji' => $emoji],
            );
        }

        $reactions = TeamMessageReaction::query()
            ->where('team_message_id', $teamMessage->id)
            ->with('user:id,name')
            ->orderBy('id')
            ->get();

        broadcast(new TeamMessageReactionsUpdated(
            $teamConversation->id,
            $teamMessage->id,
            $reactions,
        ));

        return response()->json([
            'success' => true,
            'message_id' => $teamMessage->id,
            'reactions' => $reactions->map(fn (TeamMessageReaction $r) => $r->toApiArray())->values()->all(),
        ]);
    }

    /**
     * @param  array<int, int>  $conversationIds
     * @return array<int, int>
     */
    private function unreadCountsForUser(User $user, array $conversationIds): array
    {
        if ($conversationIds === []) {
            return [];
        }

        $rows = DB::table('team_messages as m')
            ->join('team_conversation_user as cu', function ($join) use ($user): void {
                $join->on('cu.team_conversation_id', '=', 'm.team_conversation_id')
                    ->where('cu.user_id', '=', $user->id);
            })
            ->whereIn('m.team_conversation_id', $conversationIds)
            ->where('m.sender_id', '!=', $user->id)
            ->whereNull('m.deleted_at')
            ->whereRaw('m.id > COALESCE(cu.last_read_message_id, 0)')
            ->groupBy('m.team_conversation_id')
            ->selectRaw('m.team_conversation_id as cid, COUNT(*) as c')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row->cid] = (int) $row->c;
        }

        return $out;
    }

    /**
     * @return array{id: int, type: string, title: string, subtitle: string|null, is_pinned: bool}
     */
    private function conversationHeaderPayload(User $user, TeamConversation $conversation): array
    {
        $loaded = $user->teamConversations()
            ->where('team_conversations.id', $conversation->id)
            ->with(['department:id,name', 'userLow:id,name', 'userHigh:id,name'])
            ->withPivot('pinned_at')
            ->first();

        $item = $loaded !== null
            ? $this->transformConversationListItem($user, $loaded, 0)
            : $this->transformConversationListItem(
                $user,
                $conversation->loadMissing(['department:id,name', 'userLow:id,name', 'userHigh:id,name']),
                0,
            );

        return [
            'id' => (int) $item['id'],
            'type' => (string) $item['type'],
            'title' => (string) $item['title'],
            'subtitle' => isset($item['subtitle']) ? (string) $item['subtitle'] : null,
            'is_pinned' => (bool) ($item['is_pinned'] ?? false),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function transformConversationListItem(User $user, TeamConversation $c, int $unreadCount): array
    {
        $title = 'Чат';
        $subtitle = null;

        if ($c->isDepartment() && $c->relationLoaded('department') && $c->department) {
            $title = $c->department->name;
            $subtitle = 'Чат отдела';
        }

        if ($c->isDirect()) {
            $otherId = $c->user_low_id === $user->id ? $c->user_high_id : $c->user_low_id;
            $other = null;
            if ($c->relationLoaded('userLow') && $c->relationLoaded('userHigh')) {
                $other = $c->user_low_id === $user->id ? $c->userHigh : $c->userLow;
            }
            $title = $other?->name ?? ('Пользователь #'.$otherId);
            $subtitle = 'Личные сообщения';
        }

        $pinnedAt = $c->pivot?->pinned_at ?? null;

        return [
            'id' => $c->id,
            'type' => $c->type,
            'title' => $title,
            'subtitle' => $subtitle,
            'department_id' => $c->department_id,
            'unread_count' => $unreadCount,
            'last_message_at' => $c->last_message_at?->toIso8601String(),
            'last_message_preview' => $c->last_message_preview,
            'is_pinned' => $pinnedAt !== null,
            'pinned_at' => $pinnedAt?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function readMetaPayload(User $viewer, TeamConversation $conversation): array
    {
        return [
            'conversation_type' => $conversation->type,
            'viewer_last_read_message_id' => $this->viewerLastReadMessageId($viewer, $conversation),
            'peer_last_read_message_id' => $this->directPeerLastReadMessageId($viewer, $conversation),
            'peer_last_delivered_message_id' => $this->directPeerLastDeliveredMessageId($viewer, $conversation),
            'others_min_last_delivered_message_id' => $conversation->isDepartment()
                ? $this->departmentOthersMinLastDeliveredMessageId($viewer, $conversation)
                : null,
            'others_min_last_read_message_id' => $conversation->isDepartment()
                ? $this->departmentOthersMinLastReadMessageId($viewer, $conversation)
                : null,
        ];
    }

    private function viewerLastReadMessageId(User $viewer, TeamConversation $conversation): ?int
    {
        $raw = DB::table('team_conversation_user')
            ->where('team_conversation_id', $conversation->id)
            ->where('user_id', $viewer->id)
            ->value('last_read_message_id');

        if ($raw === null) {
            return null;
        }

        return (int) $raw;
    }

    private function directPeerLastReadMessageId(User $viewer, TeamConversation $conversation): ?int
    {
        if (! $conversation->isDirect()) {
            return null;
        }

        $raw = DB::table('team_conversation_user')
            ->where('team_conversation_id', $conversation->id)
            ->where('user_id', '!=', $viewer->id)
            ->value('last_read_message_id');

        if ($raw === null) {
            return null;
        }

        return (int) $raw;
    }

    private function directPeerLastDeliveredMessageId(User $viewer, TeamConversation $conversation): ?int
    {
        if (! $conversation->isDirect()) {
            return null;
        }

        $raw = DB::table('team_conversation_user')
            ->where('team_conversation_id', $conversation->id)
            ->where('user_id', '!=', $viewer->id)
            ->value('last_delivered_message_id');

        if ($raw === null) {
            return null;
        }

        return (int) $raw;
    }

    private function departmentOthersMinLastDeliveredMessageId(User $viewer, TeamConversation $conversation): int
    {
        $values = DB::table('team_conversation_user')
            ->where('team_conversation_id', $conversation->id)
            ->where('user_id', '!=', $viewer->id)
            ->pluck('last_delivered_message_id');

        if ($values->isEmpty()) {
            return 0;
        }

        $min = null;
        foreach ($values as $v) {
            $n = $v === null ? 0 : (int) $v;
            $min = $min === null ? $n : min($min, $n);
        }

        return (int) $min;
    }

    private function departmentOthersMinLastReadMessageId(User $viewer, TeamConversation $conversation): int
    {
        $values = DB::table('team_conversation_user')
            ->where('team_conversation_id', $conversation->id)
            ->where('user_id', '!=', $viewer->id)
            ->pluck('last_read_message_id');

        if ($values->isEmpty()) {
            return 0;
        }

        $min = null;
        foreach ($values as $v) {
            $n = $v === null ? 0 : (int) $v;
            $min = $min === null ? $n : min($min, $n);
        }

        return (int) $min;
    }

    /**
     * @return array<int, array{id: int, name: string, email: string}>
     */
    private function searchColleagueRows(User $user, string $like): array
    {
        $companyId = $user->company_id;
        if ($companyId === null) {
            return [];
        }

        return User::query()
            ->where('company_id', $companyId)
            ->where('id', '!=', $user->id)
            ->where('is_active', true)
            ->where(function (Builder $q) use ($like): void {
                $q->where('name', 'like', $like)
                    ->orWhere('email', 'like', $like);
            })
            ->orderBy('name')
            ->limit(15)
            ->get(['id', 'name', 'email'])
            ->map(fn (User $u): array => [
                'id' => (int) $u->id,
                'name' => (string) $u->name,
                'email' => (string) ($u->email ?? ''),
            ])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function transformTeamMessageAttachment(TeamMessageAttachment $attachment): array
    {
        return [
            'id' => $attachment->id,
            'original_name' => $attachment->original_name,
            'url' => $attachment->url(),
            'mime_type' => $attachment->mime_type,
            'size' => (int) $attachment->size,
            'is_image' => $attachment->isImage(),
        ];
    }

    /**
     * @return array{id: int, sender_name: string, body_preview: string}|null
     */
    private function roomPinnedMessagePayload(?TeamMessage $m): ?array
    {
        if ($m === null) {
            return null;
        }

        $body = trim((string) $m->body);
        if ($body === '' && is_string($m->forward_quote_body) && trim((string) $m->forward_quote_body) !== '') {
            $body = trim((string) $m->forward_quote_body);
        }

        $preview = mb_substr(preg_replace('/\s+/u', ' ', $body) ?? '', 0, 200);

        return [
            'id' => (int) $m->id,
            'sender_name' => (string) ($m->sender?->name ?? '…'),
            'body_preview' => $preview,
        ];
    }

    /**
     * @param  array<int|string, string>  $mentionNamesById
     * @return array<string, mixed>
     */
    private function transformMessage(TeamMessage $m, array $mentionNamesById = []): array
    {
        $mentionedUsers = [];
        foreach ($m->mentioned_user_ids ?? [] as $uid) {
            $id = (int) $uid;
            if ($id < 1) {
                continue;
            }
            $name = $mentionNamesById[$id] ?? $mentionNamesById[(string) $id] ?? '…';
            $mentionedUsers[] = ['id' => $id, 'name' => $name];
        }

        $forward = null;
        $fromTeam = $m->forwarded_from_team_message_id !== null && (int) $m->forwarded_from_team_message_id > 0;
        $fromWhatsapp = $m->forwarded_from_message_id !== null && (int) $m->forwarded_from_message_id > 0;
        if ($fromTeam || $fromWhatsapp) {
            $forward = [
                'from_message_id' => $fromTeam
                    ? (int) $m->forwarded_from_team_message_id
                    : (int) $m->forwarded_from_message_id,
                'source_kind' => $fromWhatsapp ? 'whatsapp' : 'team',
                'source_title' => (string) ($m->forward_source_title ?? ''),
                'quote_sender_name' => (string) ($m->forward_quote_sender_name ?? ''),
                'quote_body' => (string) ($m->forward_quote_body ?? ''),
            ];
        }

        $attachments = $m->relationLoaded('attachments')
            ? $m->attachments->map(fn (TeamMessageAttachment $a) => $this->transformTeamMessageAttachment($a))->values()->all()
            : [];

        $reactions = $m->relationLoaded('reactions')
            ? $m->reactions->map(fn (TeamMessageReaction $r) => $r->toApiArray())->values()->all()
            : [];

        $linkPreview = $m->link_preview;
        if (! is_array($linkPreview) || $linkPreview === []) {
            $linkPreview = null;
        }

        return [
            'id' => $m->id,
            'team_conversation_id' => $m->team_conversation_id,
            'parent_team_message_id' => $m->parent_team_message_id !== null ? (int) $m->parent_team_message_id : null,
            'sender_id' => $m->sender_id,
            'body' => $m->body,
            'client_message_id' => $m->client_message_id,
            'mentioned_user_ids' => $m->mentioned_user_ids ?? [],
            'mentioned_users' => $mentionedUsers,
            'forward' => $forward,
            'reply_to' => $m->replyToApiFragment(),
            'attachments' => $attachments,
            'link_preview' => $linkPreview,
            'reactions' => $reactions,
            'created_at' => $m->created_at?->toIso8601String(),
            'sender' => $m->sender ? [
                'id' => $m->sender->id,
                'name' => $m->sender->name,
            ] : null,
        ];
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function authorizeTeamConversationView(User $user, TeamConversation $teamConversation): void
    {
        $this->prepareTeamChatAccess($user);
        $this->authorize('view', $teamConversation);
    }

    private function prepareTeamChatAccess(User $user): void
    {
        if ($user->hasRole('administrator')) {
            $this->teamDepartmentChatSync->syncAdministratorToAllDepartmentChats($user);
        }
    }

    /**
     * @param  Collection<int, TeamConversation>  $conversations
     * @return Collection<int, TeamConversation>
     */
    private function filterConversationsVisibleToUser(Collection $conversations, User $user): Collection
    {
        if ($user->hasRole('administrator')) {
            return $conversations;
        }

        $deptIds = $user->departmentIds();

        return $conversations->filter(function (TeamConversation $c) use ($deptIds): bool {
            if ($c->isDirect()) {
                return true;
            }

            if ($c->department_id === null) {
                return false;
            }

            return in_array((int) $c->department_id, $deptIds, true);
        })->values();
    }

    /**
     * @param  Collection<int, TeamConversation>  $conversations
     * @return Collection<int, TeamConversation>
     */
    private function sortConversationsForSidebar(Collection $conversations): Collection
    {
        return $conversations
            ->sort(function (TeamConversation $a, TeamConversation $b): int {
                $pinA = $a->pivot?->pinned_at;
                $pinB = $b->pivot?->pinned_at;
                if ($pinA !== null && $pinB === null) {
                    return -1;
                }
                if ($pinA === null && $pinB !== null) {
                    return 1;
                }
                if ($pinA !== null && $pinB !== null) {
                    $pinnedCmp = $pinB <=> $pinA;
                    if ($pinnedCmp !== 0) {
                        return $pinnedCmp;
                    }
                }

                $typeRank = static fn (TeamConversation $c): int => match ($c->type) {
                    TeamConversation::TYPE_DEPARTMENT => 0,
                    TeamConversation::TYPE_DIRECT => 1,
                    default => 2,
                };
                $typeCmp = $typeRank($a) <=> $typeRank($b);
                if ($typeCmp !== 0) {
                    return $typeCmp;
                }

                if ($a->isDepartment() && $b->isDepartment()) {
                    $nameCmp = strcasecmp(
                        (string) ($a->department?->name ?? ''),
                        (string) ($b->department?->name ?? ''),
                    );
                    if ($nameCmp !== 0) {
                        return $nameCmp;
                    }
                }

                $timeA = $a->last_message_at?->getTimestamp() ?? 0;
                $timeB = $b->last_message_at?->getTimestamp() ?? 0;
                if ($timeA !== $timeB) {
                    return $timeB <=> $timeA;
                }

                return $b->id <=> $a->id;
            })
            ->values();
    }

    private function departmentsPayload(User $user): Collection
    {
        $query = Department::query()
            ->where('is_active', true)
            ->withCount(['posts as open_count' => fn ($q) => $q->where('status', DepartmentPost::STATUS_OPEN)])
            ->withCount(['posts as in_progress_count' => fn ($q) => $q->where('status', DepartmentPost::STATUS_IN_PROGRESS)])
            ->withCount(['posts as done_count' => fn ($q) => $q->where('status', DepartmentPost::STATUS_DONE)])
            ->orderBy('parent_id')
            ->orderBy('name');

        if (! $user->hasRole('administrator')) {
            $userDeptIds = $user->departmentIds();
            if ($userDeptIds === []) {
                return collect();
            }
            $query->whereIn('id', $userDeptIds);
        }

        return $query->get(['id', 'name', 'description', 'parent_id'])
            ->map(fn (Department $d) => [
                'id' => $d->id,
                'name' => $d->name,
                'description' => $d->description,
                'parent_id' => $d->parent_id,
                'open_count' => (int) ($d->open_count ?? 0),
                'in_progress_count' => (int) ($d->in_progress_count ?? 0),
                'done_count' => (int) ($d->done_count ?? 0),
                'posts_count' => (int) ($d->open_count ?? 0) + (int) ($d->in_progress_count ?? 0),
                'archived_posts_count' => (int) ($d->done_count ?? 0),
            ])
            ->values();
    }

    private function ensureModuleEnabled(): void
    {
        abort_unless(
            SystemSetting::getValue('module_tasks', 'on') === 'on',
            403,
            'Модуль «Задачи» отключён администратором.',
        );
    }

    private function normalizeTeamReactionEmoji(string $emoji): string
    {
        return preg_replace('/[\x{FE0F}\x{200D}]/u', '', $emoji) ?? $emoji;
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
