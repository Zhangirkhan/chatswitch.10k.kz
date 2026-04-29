<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Events\NewMessageReceived;
use App\Events\UserTyping;
use App\Http\Requests\Chat\CreateGroupRequest;
use App\Http\Requests\Chat\SendContactRequest;
use App\Http\Requests\Chat\SendMessageRequest;
use App\Http\Requests\Chat\SendPollRequest;
use App\Http\Requests\Chat\StartChatRequest;
use App\Http\Requests\Chat\SyncDepartmentsRequest;
use App\Http\Requests\Chat\ToggleMuteRequest;
use App\Http\Requests\Chat\UploadFileRequest;
use App\Jobs\SendOutboundMessageJob;
use App\Models\Chat;
use App\Models\ChatAssignment;
use App\Models\Contact;
use App\Models\Department;
use App\Models\Message;
use App\Models\MessageMedia;
use App\Models\User;
use App\Models\WhatsappSession;
use App\Services\ChatService;
use App\Services\WhatsappService;
use App\Support\MediaType;
use App\Support\OperatorSignature;
use App\Support\PhoneFormatter;
use App\Support\VCard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

final class ChatController extends Controller
{
    /**
     * Набор relations для сообщений, отдаваемых фронту — в Inertia-рендере,
     * JSON-ответах на отправку и в Echo-broadcast'ах. Один источник правды,
     * чтобы везде форма объекта `message` была идентичной (включая цитату).
     *
     * @var list<string>
     */
    private const MESSAGE_WITH = [
        'media',
        'sentByUser',
        'whatsappSession',
        'reactions.user:id,name',
        'quotedMessage:id,whatsapp_message_id,direction,type,body,sender_name,sender_phone,sent_by_user_id',
        'quotedMessage.sentByUser:id,name',
        'quotedMessage.media:id,message_id,mime_type,filename',
    ];

    public function __construct(
        private readonly ChatService $chatService,
        private readonly WhatsappService $whatsappService,
    ) {}

    public function index(Request $request): Response
    {
        $chats = $this->chatService->getChatsForUser($request->user(), $request->input('search'))
            ->where('is_archived', false)
            ->paginate(50);

        return Inertia::render('Chats/Index', [
            'chats' => $chats,
            'search' => $request->input('search'),
        ]);
    }

    public function archivedIndex(Request $request): Response
    {
        $chats = $this->chatService->getChatsForUser($request->user(), $request->input('search'))
            ->where('is_archived', true)
            ->paginate(50);

        return Inertia::render('Chats/Archived', [
            'chats' => $chats,
            'search' => $request->input('search'),
        ]);
    }

    public function show(Request $request, Chat $chat): Response
    {
        $this->authorize('view', $chat);

        $chat->load([
            'contact',
            'whatsappSession',
            'assignments.user',
            'departments',
            'pinnedMessage' => function ($q) {
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

        $messages = $chat->messages()
            ->with(self::MESSAGE_WITH)
            ->orderByDesc('message_timestamp')
            ->paginate(50);

        $allChats = $this->chatService->getChatsForUser($request->user())
            ->where('is_archived', false)
            ->paginate(50);

        // «Единая клиентская база»: все чаты того же клиента (contact), включая разные WA-номера.
        // Нужен, чтобы в панели контакта показывать: с этим человеком общались с WA #1 и WA #2.
        $contactChats = $chat->contact_id !== null
            ? Chat::with('whatsappSession:id,session_name,display_name,display_color,phone_number,status')
                ->where('contact_id', $chat->contact_id)
                ->orderByDesc('last_message_at')
                ->get([
                    'id', 'whatsapp_session_id', 'contact_id', 'chat_name',
                    'last_message_text', 'last_message_at', 'last_message_direction',
                    'unread_count', 'is_archived',
                ])
            : collect();

        return Inertia::render('Chats/Show', [
            'chat' => $chat,
            'messages' => $messages,
            'chats' => $allChats,
            'contactChats' => $contactChats,
            'departments' => Department::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'assignableUsers' => $this->assignableUsersFor($request->user(), $chat),
        ]);
    }

    public function syncDepartments(SyncDepartmentsRequest $request, Chat $chat): JsonResponse
    {
        $oldIds = $chat->departments()->pluck('departments.id')->all();
        $newIds = array_values(array_unique(array_map('intval', $request->input('department_ids', []))));

        $chat->departments()->sync($newIds);
        $chat->load('departments');

        $this->chatService->logDepartmentChange($chat, $request->user(), $oldIds, $newIds);

        return response()->json([
            'success' => true,
            'departments' => $chat->departments()->get(['departments.id', 'departments.name']),
        ]);
    }

    public function sendMessage(SendMessageRequest $request, Chat $chat): JsonResponse
    {
        $chat->load('whatsappSession');
        $session = $chat->whatsappSession;
        $quotedMessageId = $request->input('quoted_message_id');
        $text = (string) $request->input('message'); // text used for WhatsApp sending
        $displayText = (string) ($request->input('display_message') ?? '');
        if (trim($displayText) === '') {
            $displayText = $text;
        }
        $mentionsRaw = $request->input('mentions', []);
        $mentionsMetaRaw = $request->input('mentions_meta', []);
        $mentions = is_array($mentionsRaw)
            ? array_values(array_filter(array_map(
                static fn ($m) => is_string($m) ? $m : null,
                $mentionsRaw
            )))
            : [];
        $mentionsMeta = [];
        if (is_array($mentionsMetaRaw)) {
            foreach ($mentionsMetaRaw as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $id = isset($row['id']) ? trim((string) $row['id']) : '';
                $number = isset($row['number']) ? preg_replace('/\D/', '', (string) $row['number']) : '';
                $label = isset($row['label']) ? trim((string) $row['label']) : '';
                if ($id === '' || $number === '' || $label === '') {
                    continue;
                }
                $mentionsMeta[] = [
                    'id' => $id,
                    'number' => $number,
                    'label' => $label,
                ];
            }
        }

        // Fail-safe: если UI не прислал mentions, пробуем извлечь из текста "@7700..."
        // (это то, как whatsapp-web.js ожидает упоминания в message body).
        if ($mentions === []) {
            $found = [];
            if (preg_match_all('/(^|\s)@(\d{5,20})\b/u', $text, $m)) {
                /** @var array<int, string> $nums */
                $nums = $m[2] ?? [];
                foreach ($nums as $n) {
                    $n = preg_replace('/\D/', '', (string) $n);
                    if ($n !== '') {
                        $found[] = $n;
                    }
                }
            }
            if ($found !== []) {
                $mentions = array_values(array_unique($found));
            }
        }

        try {
            \Illuminate\Support\Facades\Log::info('chat.sendMessage mention debug', [
                'chat_id' => $chat->id,
                'is_group' => (bool) $chat->is_group,
                'mentions_raw_type' => gettype($mentionsRaw),
                'mentions_raw_n' => is_array($mentionsRaw) ? count($mentionsRaw) : null,
                'mentions_before_norm_n' => count($mentions),
                'text_has_at' => str_contains($text, '@'),
                'text_preview' => mb_substr(preg_replace('/\s+/u', ' ', $text) ?: '', 0, 120),
            ]);
        } catch (\Throwable $e) {
            // ignore
        }

        // whatsapp-web.js ожидает полный JID (например, 7700...@c.us).
        // UI может прислать просто digits — нормализуем.
        $mentions = array_values(array_filter(array_map(
            static function ($m): ?string {
                if (!is_string($m)) return null;
                $m = trim($m);
                if ($m === '') return null;
                if (str_contains($m, '@')) return $m;
                return preg_replace('/\D/', '', $m) . '@c.us';
            },
            $mentions
        )));

        // Подпись оператора («*Имя (Должность)*») уходит и в WhatsApp, и в БД —
        // так клиент и оператор видят сообщение в одинаковом виде.
        $signedText = OperatorSignature::prepend($request->user(), $text);
        $signedDisplayText = OperatorSignature::prepend($request->user(), $displayText);

        $message = $this->chatService->storeOutboundMessage(
            $chat,
            $session,
            $request->user(),
            $signedDisplayText,
            null,
            $quotedMessageId,
        );

        if ($mentionsMeta !== []) {
            $meta = is_array($message->metadata) ? $message->metadata : [];
            $meta['mentions'] = array_slice($mentionsMeta, 0, 20);
            $message->forceFill(['metadata' => $meta])->saveQuietly();
        }

        $message->load(self::MESSAGE_WITH);
        broadcast(new NewMessageReceived($message, $chat->id));

        SendOutboundMessageJob::dispatch(
            $message->id,
            'text',
            [
                'body' => $signedText,
                'quoted_message_id' => $quotedMessageId,
                'mentions' => $mentions,
            ],
        );

        return response()->json(['success' => true, 'message' => $message]);
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

    public function togglePin(Chat $chat): JsonResponse
    {
        $this->authorize('manage', $chat);

        $chat->update(['is_pinned' => ! $chat->is_pinned]);

        return response()->json(['success' => true, 'is_pinned' => $chat->is_pinned]);
    }

    public function pinMessage(Request $request, Chat $chat): JsonResponse
    {
        $this->authorize('view', $chat);

        $data = $request->validate([
            'message_id' => ['required', 'integer', 'exists:messages,id'],
        ]);

        $message = Message::query()
            ->where('id', (int) $data['message_id'])
            ->where('chat_id', $chat->id)
            ->first();

        if (! $message) {
            return response()->json(['success' => false, 'error' => 'Сообщение не найдено в этом чате.'], 422);
        }

        $chat->update(['pinned_message_id' => $message->id]);
        $chat->loadMissing('pinnedMessage.sentByUser:id,name');

        return response()->json(['success' => true, 'pinned_message' => $chat->pinnedMessage]);
    }

    public function unpinMessage(Request $request, Chat $chat): JsonResponse
    {
        $this->authorize('view', $chat);

        $chat->update(['pinned_message_id' => null]);

        return response()->json(['success' => true]);
    }

    public function archive(Chat $chat): JsonResponse
    {
        $this->authorize('manage', $chat);

        $chat->update(['is_archived' => ! $chat->is_archived]);

        return response()->json(['success' => true, 'is_archived' => $chat->is_archived]);
    }

    public function toggleMute(ToggleMuteRequest $request, Chat $chat): JsonResponse
    {
        $shouldUnmute = $request->boolean('unmute') || $chat->is_muted;

        if ($shouldUnmute && ! $request->filled('duration')) {
            $chat->update(['is_muted' => false, 'muted_until' => null]);

            return response()->json(['success' => true, 'is_muted' => false, 'muted_until' => null]);
        }

        $mutedUntil = match ($request->input('duration', 'always')) {
            '8h' => now()->addHours(8),
            '1w' => now()->addWeek(),
            default => null,
        };

        $chat->update(['is_muted' => true, 'muted_until' => $mutedUntil]);

        return response()->json([
            'success' => true,
            'is_muted' => true,
            'muted_until' => $mutedUntil?->toISOString(),
        ]);
    }

    public function toggleFavorite(Chat $chat): JsonResponse
    {
        $this->authorize('manage', $chat);

        $chat->update(['is_favorite' => ! $chat->is_favorite]);

        return response()->json(['success' => true, 'is_favorite' => $chat->is_favorite]);
    }

    public function toggleUnread(Chat $chat): JsonResponse
    {
        $this->authorize('manage', $chat);

        $chat->update(['unread_count' => $chat->unread_count > 0 ? 0 : 1]);

        return response()->json(['success' => true, 'unread_count' => $chat->unread_count]);
    }

    public function clear(Chat $chat): JsonResponse
    {
        $this->authorize('manage', $chat);

        $chat->messages()->delete();
        $chat->update([
            'last_message_text' => null,
            'last_message_at' => null,
            'last_message_direction' => null,
            'unread_count' => 0,
        ]);

        return response()->json(['success' => true]);
    }

    public function saveContact(Request $request, Chat $chat): JsonResponse
    {
        $this->authorize('view', $chat);

        abort_if($chat->is_group, 422, 'Нельзя сохранять контакт для группы.');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
        ]);

        $name = trim((string) $data['name']);
        if ($name === '') {
            return response()->json(['success' => false, 'error' => 'Имя не может быть пустым.'], 422);
        }

        $chat->loadMissing('contact');

        $phone = $chat->contact?->phone_number;
        if (! $phone) {
            // Fallback: derive from whatsapp_chat_id for 1:1 chats
            $phone = PhoneFormatter::fromWhatsappId($chat->whatsapp_chat_id);
        }

        if (! $phone) {
            return response()->json(['success' => false, 'error' => 'Не удалось определить номер контакта.'], 422);
        }

        $contact = $chat->contact ?: $this->chatService->findOrCreateContactByPhone($phone);

        // Save exactly what operator entered.
        $contact->name = $name;
        $contact->saveQuietly();

        if (! $chat->contact_id) {
            $chat->update(['contact_id' => $contact->id]);
        }

        // Keep the UI chat title consistent with the saved contact name.
        // (Lists/side panels prefer `chat.chat_name`.)
        $chat->update(['chat_name' => $name]);

        return response()->json(['success' => true, 'contact' => $contact]);
    }

    public function contacts(Request $request): JsonResponse
    {
        $search = trim((string) $request->input('search', ''));
        $sessionId = (int) $request->input('whatsapp_session_id', 0);

        $query = Contact::query()->orderByRaw('COALESCE(name, push_name, phone_number) asc');

        if ($search !== '') {
            $digits = preg_replace('/\D/', '', $search);
            $query->where(function ($q) use ($search, $digits) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('push_name', 'like', "%{$search}%")
                    ->orWhere('phone_number', 'like', "%{$search}%");
                if (is_string($digits) && $digits !== '') {
                    $q->orWhere('whatsapp_id', 'like', "%{$digits}%");
                }
            });
        }

        $recentChats = $this->chatService->getChatsForUser($request->user())
            ->when($sessionId > 0, fn ($q) => $q->where('whatsapp_session_id', $sessionId))
            ->whereNotNull('contact_id')
            ->where('is_group', false)
            ->orderByDesc('last_message_at')
            ->limit(200)
            ->get(['contact_id', 'chat_name', 'last_message_at']);

        $recentContactIds = $recentChats
            ->pluck('contact_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
        $chatNameByContactId = $recentChats
            ->filter(fn (Chat $c) => $c->contact_id !== null && trim((string) $c->chat_name) !== '')
            ->mapWithKeys(fn (Chat $c) => [(int) $c->contact_id => trim((string) $c->chat_name)]);

        $contacts = $query->limit(300)->get();
        if ($recentContactIds !== []) {
            $priority = array_flip($recentContactIds);
            $contacts = $contacts
                ->sortBy(fn (Contact $c) => $priority[$c->id] ?? PHP_INT_MAX)
                ->values();
        }
        $contacts = $contacts
            ->map(function (Contact $c) use ($chatNameByContactId) {
                $saved = trim((string) ($c->name ?? ''));
                $chatName = trim((string) ($chatNameByContactId[$c->id] ?? ''));
                $push = trim((string) ($c->push_name ?? ''));
                $phone = trim((string) ($c->phone_number ?? ''));
                $savedLooksLikeWaNick = $saved !== '' && $push !== '' && mb_strtolower($saved) === mb_strtolower($push);
                $displayName = $saved !== '' && ! $savedLooksLikeWaNick
                    ? $saved
                    : ($chatName !== '' ? $chatName : ($saved !== '' ? $saved : ($push !== '' ? $push : $phone)));

                return [
                    'id' => $c->id,
                    'whatsapp_id' => $c->whatsapp_id,
                    'phone_number' => $c->phone_number,
                    'name' => $c->name,
                    'push_name' => $c->push_name,
                    'profile_picture_url' => $c->profile_picture_url,
                    'display_name' => $displayName,
                ];
            })
            ->values();

        $sessions = $this->sessionsForUser($request->user())
            ->orderBy('display_name')
            ->get(['whatsapp_sessions.id', 'session_name', 'display_name', 'phone_number', 'status']);

        return response()->json([
            'contacts' => $contacts,
            'sessions' => $sessions,
        ]);
    }

    public function start(StartChatRequest $request): RedirectResponse
    {
        $user = $request->user();
        $session = $request->resolvedSession();

        abort_unless($user->can('use', $session), 403, 'Этот номер WhatsApp вам не назначен.');

        $contact = $request->filled('contact_id')
            ? Contact::findOrFail((int) $request->input('contact_id'))
            : $this->chatService->findOrCreateContactByPhone(
                (string) $request->input('phone'),
                $request->input('name'),
            );

        $chat = $this->chatService->findOrCreateChatForContact($contact, $session);

        if ($chat->is_archived) {
            $chat->update(['is_archived' => false]);
        }

        if (! $user->hasRole('administrator')) {
            ChatAssignment::firstOrCreate(
                ['chat_id' => $chat->id, 'user_id' => $user->id],
                ['assigned_by' => $user->id],
            );
        }

        return redirect()->route('chats.show', $chat->id);
    }

    public function createGroup(CreateGroupRequest $request): JsonResponse
    {
        $user = $request->user();
        $session = WhatsappSession::findOrFail((int) $request->input('whatsapp_session_id'));

        abort_unless($user->can('use', $session), 403, 'Этот номер WhatsApp вам не назначен.');

        $participants = Contact::whereIn('id', $request->input('contact_ids'))
            ->get()
            ->map(function (Contact $c): ?string {
                $raw = (string) ($c->whatsapp_id ?: $c->phone_number ?: '');
                if ($raw === '') {
                    return null;
                }

                return str_contains($raw, '@')
                    ? $raw
                    : preg_replace('/\D/', '', $raw).'@c.us';
            })
            ->filter()
            ->values()
            ->all();

        if (empty($participants)) {
            return response()->json(['success' => false, 'error' => 'Нет корректных участников.'], 422);
        }

        $result = $this->whatsappService->createGroup(
            $session->session_name,
            (string) $request->input('subject'),
            $participants,
        );

        if (empty($result['success']) || empty($result['chatId'])) {
            return response()->json([
                'success' => false,
                'error' => $result['error'] ?? 'Не удалось создать группу.',
            ], 502);
        }

        $chat = Chat::firstOrCreate(
            ['whatsapp_chat_id' => $result['chatId'], 'whatsapp_session_id' => $session->id],
            [
                'chat_name' => $request->input('subject'),
                'is_group' => true,
                'community_id' => $request->input('community_id'),
                'last_message_at' => now(),
            ],
        );

        $communityId = $request->input('community_id');
        if ($communityId && $chat->community_id !== (int) $communityId) {
            $chat->update(['community_id' => (int) $communityId]);
        }

        if (! $user->hasRole('administrator')) {
            ChatAssignment::firstOrCreate(
                ['chat_id' => $chat->id, 'user_id' => $user->id],
                ['assigned_by' => $user->id],
            );
        }

        return response()->json(['success' => true, 'chat_id' => $chat->id]);
    }

    public function syncGroups(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            abort(401);
        }

        $sessions = $this->sessionsForUser($user)
            ->where('is_active', true)
            ->get(['id', 'session_name', 'status']);

        $created = 0;
        $updated = 0;
        $errors = [];

        foreach ($sessions as $session) {
            // Синхронизируем только для реально подключённых номеров.
            if (($session->status ?? null) !== 'connected') {
                continue;
            }

            $resp = $this->whatsappService->getChats($session->session_name);
            if (empty($resp['success'])) {
                $errors[] = [
                    'session' => $session->session_name,
                    'error' => $resp['error'] ?? 'Unknown error',
                ];

                continue;
            }

            $chats = is_array($resp['chats'] ?? null) ? $resp['chats'] : [];
            foreach ($chats as $c) {
                if (! is_array($c)) {
                    continue;
                }
                if (empty($c['id']) || empty($c['isGroup'])) {
                    continue;
                }

                $waId = (string) $c['id'];
                $name = trim((string) ($c['name'] ?? '')) ?: $waId;

                $chat = Chat::firstOrCreate(
                    ['whatsapp_chat_id' => $waId, 'whatsapp_session_id' => $session->id],
                    [
                        'chat_name' => $name,
                        'is_group' => true,
                        'last_message_at' => null,
                    ],
                );

                if ($chat->wasRecentlyCreated) {
                    $created++;
                } else {
                    if (($chat->chat_name ?? '') === '' || $chat->chat_name === $chat->whatsapp_chat_id) {
                        $chat->update(['chat_name' => $name, 'is_group' => true]);
                        $updated++;
                    } elseif (! $chat->is_group) {
                        $chat->update(['is_group' => true]);
                        $updated++;
                    }
                }
            }
        }

        return response()->json([
            'success' => true,
            'created' => $created,
            'updated' => $updated,
            'errors' => $errors,
        ]);
    }

    public function groupParticipants(Request $request, Chat $chat): JsonResponse
    {
        $this->authorize('view', $chat);

        if (! $chat->is_group) {
            return response()->json(['success' => false, 'error' => 'Not a group chat'], 422);
        }

        $chat->load('whatsappSession:id,session_name,status');
        $session = $chat->whatsappSession;
        if (! $session || ($session->status ?? null) !== 'connected') {
            return response()->json(['success' => false, 'error' => 'WhatsApp session not connected'], 409);
        }

        $resp = $this->whatsappService->getGroupParticipants($session->session_name, (string) $chat->whatsapp_chat_id);
        if (empty($resp['success'])) {
            return response()->json([
                'success' => false,
                'error' => $resp['error'] ?? 'Failed to fetch participants',
            ], 502);
        }

        $participants = is_array($resp['participants'] ?? null) ? $resp['participants'] : [];

        // Map participants to our saved contacts (by phone digits or whatsapp_id).
        $digits = [];
        foreach ($participants as $p) {
            if (! is_array($p)) {
                continue;
            }
            $raw = (string) ($p['number'] ?? '');
            $d = preg_replace('/\D/', '', $raw) ?: null;
            if ($d) {
                $digits[] = $d;
            }
            $id = (string) ($p['id'] ?? '');
            $idDigits = preg_replace('/\D/', '', $id) ?: null;
            if ($idDigits) {
                $digits[] = $idDigits;
            }
        }
        $digits = array_values(array_unique(array_filter($digits)));

        $contacts = $digits
            ? Contact::query()
                ->whereIn('phone_number', $digits)
                ->orWhereIn('whatsapp_id', $digits)
                ->orWhereIn('whatsapp_id', array_map(fn ($d) => "{$d}@c.us", $digits))
                ->get(['id', 'phone_number', 'whatsapp_id', 'name'])
            : collect();

        $byPhone = $contacts->keyBy('phone_number');
        $byWa = $contacts->keyBy('whatsapp_id');

        $mapped = array_map(function ($p) use ($byPhone, $byWa) {
            if (! is_array($p)) {
                return $p;
            }
            $rawNumber = (string) ($p['number'] ?? '');
            $d = preg_replace('/\D/', '', $rawNumber) ?: null;
            $waId = (string) ($p['id'] ?? '');
            $contact = null;
            if ($d && $byPhone->has($d)) {
                $contact = $byPhone->get($d);
            }
            if (! $contact && $waId !== '' && $byWa->has($waId)) {
                $contact = $byWa->get($waId);
            }
            if (! $contact && $d && $byWa->has("{$d}@c.us")) {
                $contact = $byWa->get("{$d}@c.us");
            }

            if ($contact && $contact->name) {
                $p['saved_name'] = $contact->name;
            }

            return $p;
        }, $participants);

        return response()->json([
            'success' => true,
            'participants' => $mapped,
        ]);
    }

    public function timeline(Request $request, Chat $chat): JsonResponse
    {
        $this->authorize('view', $chat);

        $limit = (int) $request->input('limit', 50);
        $limit = min(100, max(1, $limit));

        $beforeTs = $request->input('before_timestamp');
        $beforeId = (int) $request->input('before_id', 0);

        $messages = $chat->messages()
            ->with(self::MESSAGE_WITH)
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

        return response()->json(['messages' => $messages]);
    }

    public function uploadFile(UploadFileRequest $request, Chat $chat): JsonResponse
    {
        $file = $request->file('file');
        $chat->load('whatsappSession');
        $session = $chat->whatsappSession;

        $mime = $file->getMimeType() ?? 'application/octet-stream';
        $originalName = (string) $file->getClientOriginalName();
        if (str_ends_with(strtolower($originalName), '.webm') && ! str_contains(strtolower($mime), 'webm')) {
            $mime = 'audio/webm';
        }
        $uploadHint = (string) $request->input('type', '');
        if (in_array($uploadHint, ['voice', 'ptt'], true) && str_starts_with(strtolower($mime), 'video/')) {
            $mime = 'audio/webm';
        }
        $caption = (string) $request->input('caption', '');
        $type = MediaType::detect($mime, $request->input('type'));

        $storedPath = $file->store('whatsapp-media/'.date('Y/m'), 'local');

        // Подпись оператора идёт как caption к медиа. Для медиа без подписи
        // подпись всё равно отправляется, чтобы клиент понимал, от кого файл.
        $signedCaption = OperatorSignature::prepend($request->user(), $caption);

        $message = $this->chatService->storeOutboundMessage(
            $chat,
            $session,
            $request->user(),
            $signedCaption,
        );

        $message->forceFill(['type' => $type])->save();

        MessageMedia::create([
            'message_id' => $message->id,
            'mime_type' => $mime,
            'filename' => $originalName,
            'disk_path' => $storedPath,
            'file_size' => $file->getSize() ?: 0,
        ]);

        $chat->update(['last_message_text' => MediaType::previewText($type, $signedCaption)]);

        $message->load(self::MESSAGE_WITH);
        broadcast(new NewMessageReceived($message, $chat->id));

        SendOutboundMessageJob::dispatch(
            $message->id,
            'media',
            [
                'disk' => 'local',
                'path' => $storedPath,
                'mimetype' => $mime,
                'filename' => $originalName,
                'caption' => $type === 'voice'
                    ? null
                    : ($signedCaption !== '' ? $signedCaption : null),
            ],
        );

        return response()->json(['success' => true, 'message' => $message]);
    }

    public function sendPoll(SendPollRequest $request, Chat $chat): JsonResponse
    {
        $question = trim((string) $request->input('question'));
        $options = array_values(array_filter(
            array_map(fn ($o) => trim((string) $o), (array) $request->input('options', [])),
            fn ($o) => $o !== '',
        ));

        if (count($options) < 2) {
            return response()->json(['success' => false, 'error' => 'Добавьте хотя бы два варианта ответа.'], 422);
        }

        $allowMultiple = $request->boolean('allow_multiple_answers');
        $chat->load('whatsappSession');
        $session = $chat->whatsappSession;

        $message = $this->chatService->storeOutboundMessage(
            $chat,
            $session,
            $request->user(),
            $question,
        );

        $message->forceFill([
            'type' => 'poll',
            'metadata' => [
                'poll' => [
                    'question' => $question,
                    'options' => $options,
                    'allow_multiple_answers' => $allowMultiple,
                ],
            ],
        ])->save();

        $chat->update(['last_message_text' => '📊 '.$question]);

        $message->load(self::MESSAGE_WITH);
        broadcast(new NewMessageReceived($message, $chat->id));

        SendOutboundMessageJob::dispatch(
            $message->id,
            'poll',
            [
                'question' => $question,
                'options' => $options,
                'allow_multiple' => $allowMultiple,
            ],
        );

        return response()->json(['success' => true, 'message' => $message]);
    }

    public function sendContact(SendContactRequest $request, Chat $chat): JsonResponse
    {
        $phone = (string) $request->input('phone');
        $phoneDigits = preg_replace('/\D/', '', $phone) ?: $phone;
        $displayName = trim((string) $request->input('name')) ?: $phoneDigits;

        $chat->load('whatsappSession');
        $session = $chat->whatsappSession;

        $vcard = VCard::build($displayName, $phoneDigits, $request->input('email'), $request->input('company'));

        $message = $this->chatService->storeOutboundMessage(
            $chat,
            $session,
            $request->user(),
            $displayName,
        );

        $message->forceFill([
            'type' => 'contact',
            'metadata' => [
                'contact' => [
                    'id' => $request->input('contact_id'),
                    'name' => $displayName,
                    'phone' => $phoneDigits,
                    'email' => $request->input('email'),
                    'company' => $request->input('company'),
                    'avatar_url' => $request->input('avatar_url'),
                    'vcard' => $vcard,
                ],
            ],
        ])->save();

        $chat->update(['last_message_text' => '👤 '.$displayName]);

        $message->load(self::MESSAGE_WITH);
        broadcast(new NewMessageReceived($message, $chat->id));

        SendOutboundMessageJob::dispatch(
            $message->id,
            'contact',
            ['vcard' => $vcard, 'display_name' => $displayName],
        );

        return response()->json(['success' => true, 'message' => $message]);
    }

    private function assignableUsersFor(?User $user, ?Chat $chat = null): Collection
    {
        if (! $user) {
            return collect();
        }

        $query = User::query()
            ->where('is_active', true)
            ->with(['roles:id,name', 'department:id,name'])
            ->orderBy('name');

        /** @var list<int>|null Отделы чата для сортировки списка у администратора (сверху — из отделов чата). */
        $adminChatDepartmentIds = null;

        if ($user->hasRole('administrator')) {
            if ($chat === null) {
                return collect();
            }
            $rawDeptIds = $chat->relationLoaded('departments')
                ? $chat->departments->pluck('id')->all()
                : $chat->departments()->pluck('departments.id')->all();
            if ($rawDeptIds === []) {
                // Админ сначала прикрепляет к чату отдел(а) — до этого список пустой.
                return collect();
            }
            // Полный список активных пользователей; новых можно сохранить только из отделов чата (см. ChatAssignmentController).
            $adminChatDepartmentIds = array_values(array_map(intval(...), $rawDeptIds));
        } elseif ($user->hasRole('manager')) {
            $query->where('department_id', $user->department_id);
        } else {
            return collect();
        }

        $users = $query->get(['id', 'name', 'email', 'department_id']);

        if ($adminChatDepartmentIds !== null) {
            $users = $users->sortBy(function (User $u) use ($adminChatDepartmentIds): array {
                $uid = $u->department_id !== null ? (int) $u->department_id : null;
                $inChatDept = $uid !== null && in_array($uid, $adminChatDepartmentIds, true);

                return [$inChatDept ? 0 : 1, mb_strtolower($u->name)];
            })->values();
        }

        return $users
            ->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'department_id' => $u->department_id,
                'department_name' => $u->department?->name,
                'roles' => $u->roles->pluck('name')->all(),
            ])
            ->values();
    }

    private function sessionsForUser(?User $user)
    {
        $query = WhatsappSession::where('is_active', true);

        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->hasRole('administrator')) {
            return $query;
        }

        return $query->whereIn(
            'whatsapp_sessions.id',
            $user->whatsappSessions()->pluck('whatsapp_sessions.id'),
        );
    }
}
