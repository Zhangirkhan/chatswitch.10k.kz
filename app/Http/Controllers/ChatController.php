<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Events\NewMessageReceived;
use App\Events\UserTyping;
use App\Models\Chat;
use App\Models\ChatAssignment;
use App\Models\Contact;
use App\Models\Message;
use App\Models\WhatsappSession;
use App\Services\ChatService;
use App\Services\WhatsappService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class ChatController extends Controller
{
    public function __construct(
        private readonly ChatService $chatService,
        private readonly WhatsappService $whatsappService,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $search = $request->input('search');

        $chats = $this->chatService->getChatsForUser($user, $search)
            ->where('is_archived', false)
            ->paginate(50);

        return Inertia::render('Chats/Index', [
            'chats' => $chats,
            'search' => $search,
        ]);
    }

    public function archivedIndex(Request $request): Response
    {
        $user = $request->user();
        $search = $request->input('search');

        $chats = $this->chatService->getChatsForUser($user, $search)
            ->where('is_archived', true)
            ->paginate(50);

        return Inertia::render('Chats/Archived', [
            'chats' => $chats,
            'search' => $search,
        ]);
    }

    public function show(Request $request, Chat $chat): Response
    {
        $user = $request->user();

        if (! $this->canAccessChat($user, $chat)) {
            abort(403);
        }

        $chat->load(['contact', 'whatsappSession', 'assignments.user']);

        $messages = $chat->messages()
            ->with(['media', 'sentByUser', 'whatsappSession', 'reactions.user:id,name'])
            ->orderByDesc('message_timestamp')
            ->paginate(50);

        $allChats = $this->chatService->getChatsForUser($user)
            ->where('is_archived', false)
            ->paginate(50);

        return Inertia::render('Chats/Show', [
            'chat' => $chat,
            'messages' => $messages,
            'chats' => $allChats,
        ]);
    }

    public function sendMessage(Request $request, Chat $chat): JsonResponse
    {
        $request->validate([
            'message' => 'required|string|max:4096',
            'quoted_message_id' => 'nullable|string|max:255',
        ]);

        $user = $request->user();
        if (! $this->canAccessChat($user, $chat)) {
            abort(403);
        }

        $chat->load('whatsappSession');
        $session = $chat->whatsappSession;

        $quotedMessageId = $request->input('quoted_message_id');

        $result = $this->whatsappService->sendMessage(
            $session->session_name,
            $chat->whatsapp_chat_id,
            $request->input('message'),
            $quotedMessageId,
        );

        $waMessageId = $result['messageId'] ?? null;

        $message = $this->chatService->storeOutboundMessage(
            $chat,
            $session,
            $user,
            $request->input('message'),
            $waMessageId,
            $quotedMessageId,
        );

        $message->load(['media', 'sentByUser', 'whatsappSession', 'reactions.user:id,name']);
        broadcast(new NewMessageReceived($message, $chat->id));

        return response()->json(['success' => true, 'message' => $message]);
    }

    public function typing(Request $request, Chat $chat): JsonResponse
    {
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
        $chat->update(['is_pinned' => ! $chat->is_pinned]);

        return response()->json(['success' => true, 'is_pinned' => $chat->is_pinned]);
    }

    public function archive(Chat $chat): JsonResponse
    {
        $chat->update(['is_archived' => ! $chat->is_archived]);

        return response()->json(['success' => true, 'is_archived' => $chat->is_archived]);
    }

    public function clear(Chat $chat): JsonResponse
    {
        $chat->messages()->delete();
        $chat->update([
            'last_message_text' => null,
            'last_message_at' => null,
            'unread_count' => 0,
        ]);

        return response()->json(['success' => true]);
    }

    public function destroy(Chat $chat): RedirectResponse
    {
        $chat->messages()->delete();
        $chat->assignments()->delete();
        $chat->delete();

        return redirect()->route('chats.index');
    }

    public function contacts(Request $request): JsonResponse
    {
        $search = trim((string) $request->input('search', ''));

        $query = Contact::query()->orderByRaw('COALESCE(name, push_name, phone_number) asc');

        if ($search !== '') {
            $digits = preg_replace('/\D/', '', $search);
            $query->where(function ($q) use ($search, $digits) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('push_name', 'like', "%{$search}%")
                    ->orWhere('phone_number', 'like', "%{$search}%");
                if ($digits !== '' && $digits !== null) {
                    $q->orWhere('whatsapp_id', 'like', "%{$digits}%");
                }
            });
        }

        $contacts = $query->limit(300)->get();

        $sessions = WhatsappSession::where('is_active', true)
            ->orderBy('display_name')
            ->get(['id', 'session_name', 'display_name', 'phone_number', 'status']);

        return response()->json([
            'contacts' => $contacts,
            'sessions' => $sessions,
        ]);
    }

    public function start(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'contact_id' => 'nullable|integer|exists:contacts,id',
            'phone' => 'nullable|string|max:32',
            'name' => 'nullable|string|max:120',
            'whatsapp_session_id' => 'required|integer|exists:whatsapp_sessions,id',
        ]);

        if (empty($data['contact_id']) && empty($data['phone'])) {
            return back()->withErrors(['phone' => 'Укажите контакт или номер телефона.']);
        }

        $user = $request->user();
        $session = WhatsappSession::findOrFail($data['whatsapp_session_id']);

        $contact = ! empty($data['contact_id'])
            ? Contact::findOrFail($data['contact_id'])
            : $this->chatService->findOrCreateContactByPhone($data['phone'], $data['name'] ?? null);

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

    public function timeline(Request $request, Chat $chat): JsonResponse
    {
        $messages = $chat->messages()
            ->with(['media', 'sentByUser', 'whatsappSession', 'reactions.user:id,name'])
            ->orderByDesc('message_timestamp')
            ->when($request->input('before_timestamp'), function ($q, $ts) {
                $q->where('message_timestamp', '<', $ts);
            })
            ->limit((int) $request->input('limit', 50))
            ->get()
            ->reverse()
            ->values();

        return response()->json(['messages' => $messages]);
    }

    public function uploadFile(Request $request, Chat $chat): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|max:65536',
            'caption' => 'nullable|string|max:1024',
            'type' => 'nullable|string|in:image,video,audio,voice,sticker,gif,document',
        ]);

        $user = $request->user();
        if (! $this->canAccessChat($user, $chat)) {
            abort(403);
        }

        $file = $request->file('file');
        $chat->load('whatsappSession');
        $session = $chat->whatsappSession;

        $mime = $file->getMimeType() ?? 'application/octet-stream';
        $originalName = $file->getClientOriginalName();
        $caption = $request->input('caption');
        $binary = file_get_contents($file->getRealPath());
        $base64 = base64_encode($binary);

        $type = $request->input('type') ?: $this->detectMediaType($mime, $originalName);
        $bodyText = $caption ?: '';

        $result = $this->whatsappService->sendMedia(
            $session->session_name,
            $chat->whatsapp_chat_id,
            $base64,
            $mime,
            $originalName,
            $caption,
        );

        $message = $this->chatService->storeOutboundMessage(
            $chat,
            $session,
            $user,
            $bodyText,
            $result['messageId'] ?? null,
        );

        $message->update(['type' => $type]);

        $this->chatService->storeOutboundMedia($message, $binary, $mime, $originalName);

        $chat->update([
            'last_message_text' => $caption ?: $this->mediaPreviewText($type),
        ]);

        $message->load(['media', 'sentByUser', 'whatsappSession', 'reactions.user:id,name']);
        broadcast(new NewMessageReceived($message, $chat->id));

        return response()->json(['success' => true, 'message' => $message]);
    }

    private function detectMediaType(string $mime, ?string $filename): string
    {
        if ($mime === 'image/webp') {
            return 'sticker';
        }
        if ($mime === 'image/gif') {
            return 'gif';
        }
        if (str_starts_with($mime, 'image/')) {
            return 'image';
        }
        if (str_starts_with($mime, 'video/')) {
            return 'video';
        }
        if (str_starts_with($mime, 'audio/')) {
            return 'audio';
        }

        return 'document';
    }

    private function mediaPreviewText(string $type): string
    {
        return match ($type) {
            'image' => '📷 Фото',
            'video' => '🎥 Видео',
            'audio' => '🎵 Аудио',
            'voice' => '🎤 Голосовое сообщение',
            'sticker' => 'Стикер',
            'gif' => 'GIF',
            'document' => '📄 Документ',
            default => '📎 Файл',
        };
    }

    private function canAccessChat(\App\Models\User $user, Chat $chat): bool
    {
        if ($user->hasRole('administrator')) {
            return true;
        }

        if ($user->hasRole('manager')) {
            $departmentUserIds = \App\Models\User::where('department_id', $user->department_id)->pluck('id');

            return $chat->assignments()->whereIn('user_id', $departmentUserIds)->exists();
        }

        return $chat->assignments()->where('user_id', $user->id)->exists();
    }
}
