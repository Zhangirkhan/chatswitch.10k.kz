<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Events\MessageReactionsUpdated;
use App\Jobs\SendOutboundMessageJob;
use App\Jobs\SyncMessageReactionToWhatsappJob;
use App\Models\Contact;
use App\Models\Message;
use App\Models\MessageMedia;
use App\Models\MessageReaction;
use App\Models\User;
use App\Models\WhatsappSession;
use App\Services\ChatService;
use App\Services\WhatsappService;
use App\Support\MediaType;
use App\Support\OperatorSignature;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

final class MessageController extends Controller
{
    public function __construct(
        private readonly ChatService $chatService,
    ) {}

    public function react(Request $request, Message $message, WhatsappService $whatsappService): JsonResponse
    {
        $data = $request->validate([
            'emoji' => 'required|string|max:16',
        ]);

        $user = $request->user();
        $emoji = $data['emoji'];
        $normalizedNew = $this->normalizeEmoji($emoji);

        $existing = MessageReaction::where('message_id', $message->id)
            ->where('user_id', $user->id)
            ->first();

        $reactionToSync = $existing && $this->normalizeEmoji($existing->emoji) === $normalizedNew
            ? ''
            : $emoji;

        $message->loadMissing(['whatsappSession', 'chat']);
        $sessionName = $message->whatsappSession?->session_name;
        $whatsappMessageId = (string) ($message->whatsapp_message_id ?? '');
        $whatsappMessageIdForReaction = $this->normalizeWhatsappMessageIdForReaction(
            $whatsappMessageId,
            (string) ($message->chat?->whatsapp_chat_id ?? ''),
        );

        if ($sessionName === '' || $whatsappMessageId === '') {
            // Deferred reaction: store locally now, sync later when message has WhatsApp id.
            if ($existing && $this->normalizeEmoji($existing->emoji) === $normalizedNew) {
                $existing->delete();
            } else {
                $reaction = MessageReaction::updateOrCreate(
                    ['message_id' => $message->id, 'user_id' => $user->id],
                    [
                        'emoji' => $emoji,
                        'pending_whatsapp_sync' => true,
                        'whatsapp_synced_at' => null,
                        'whatsapp_sync_error' => null,
                    ],
                );
                SyncMessageReactionToWhatsappJob::dispatch($reaction->id)->delay(now()->addSeconds(3));
            }

            $reactions = MessageReaction::with('user:id,name')
                ->where('message_id', $message->id)
                ->get();

            broadcast(new MessageReactionsUpdated($message->chat_id, $message->id, $reactions));

            try {
                Log::warning('[ui-react] cannot sync reaction', [
                    'message_id' => $message->id,
                    'chat_id' => $message->chat_id,
                    'direction' => $message->direction,
                    'whatsapp_session_id' => $message->whatsapp_session_id,
                    'whatsapp_message_id' => $message->whatsapp_message_id,
                    'session_name' => $sessionName,
                ]);
            } catch (\Throwable) {
                // ignore log sink failures
            }

            return response()->json([
                'success' => true,
                'deferred' => true,
                'reactions' => $reactions,
            ]);
        }

        try {
            Log::info('[ui-react] syncing reaction to whatsapp', [
                'message_id' => $message->id,
                'chat_id' => $message->chat_id,
                'session_name' => $sessionName,
                'whatsapp_message_id' => $whatsappMessageId,
                'whatsapp_message_id_for_reaction' => $whatsappMessageIdForReaction,
                'reaction' => $reactionToSync,
            ]);
        } catch (\Throwable) {
            // ignore log sink failures
        }

        $syncResult = $whatsappService->reactToMessage($sessionName, $whatsappMessageIdForReaction, $reactionToSync);
        if (($syncResult['success'] ?? false) !== true) {
            try {
                Log::warning('[ui-react] whatsapp sync failed', [
                    'message_id' => $message->id,
                    'chat_id' => $message->chat_id,
                    'session_name' => $sessionName,
                    'whatsapp_message_id' => $whatsappMessageId,
                    'whatsapp_message_id_for_reaction' => $whatsappMessageIdForReaction,
                    'reaction' => $reactionToSync,
                    'result' => $syncResult,
                ]);
            } catch (\Throwable) {
                // ignore log sink failures
            }

            return response()->json([
                'success' => false,
                'message' => (string) ($syncResult['error'] ?? 'Failed to sync reaction to WhatsApp.'),
            ], 502);
        }

        if ($existing && $this->normalizeEmoji($existing->emoji) === $normalizedNew) {
            $existing->delete();
        } else {
            MessageReaction::updateOrCreate(
                ['message_id' => $message->id, 'user_id' => $user->id],
                [
                    'emoji' => $emoji,
                    'pending_whatsapp_sync' => false,
                    'whatsapp_synced_at' => now(),
                    'whatsapp_sync_error' => null,
                ],
            );
        }

        $reactions = MessageReaction::with('user:id,name')
            ->where('message_id', $message->id)
            ->get();

        broadcast(new MessageReactionsUpdated($message->chat_id, $message->id, $reactions));

        return response()->json([
            'success' => true,
            'reactions' => $reactions,
        ]);
    }

    private function normalizeEmoji(string $emoji): string
    {
        return preg_replace('/[\x{FE0F}\x{200D}]/u', '', $emoji) ?? $emoji;
    }

    /**
     * WhatsApp Web может отдавать messageId в формате *@lid* для linked-device.
     * Реакции по такому id иногда не синхронизируются собеседнику.
     * Здесь конвертируем serialized id в формат с реальным chatId (@c.us / @g.us), если можем.
     */
    private function normalizeWhatsappMessageIdForReaction(string $messageId, string $chatWhatsappId): string
    {
        $messageId = trim($messageId);
        $chatWhatsappId = trim($chatWhatsappId);
        if ($messageId === '' || $chatWhatsappId === '') {
            return $messageId;
        }

        // serialized message id looks like: fromMe_remote_id or fromMe_remote_participant_id
        $parts = explode('_', $messageId);
        if (count($parts) < 3) {
            return $messageId;
        }

        $remote = $parts[1] ?? '';
        if (! str_contains($remote, '@lid')) {
            return $messageId;
        }

        $parts[1] = $chatWhatsappId;

        return implode('_', $parts);
    }

    public function destroy(Request $request, Message $message): JsonResponse
    {
        $user = $request->user();

        if (! $user->hasRole('administrator')) {
            // Удалять можно только свои исходящие сообщения.
            if ($message->direction !== 'outbound' || (int) $message->sent_by_user_id !== (int) $user->id) {
                abort(403);
            }
        }

        $message->reactions()->delete();
        $message->media()->delete();
        $message->delete();

        return response()->json(['success' => true]);
    }

    /**
     * Текст исходящего при пересылке: как у обычной отправки — с подписью оператора.
     * Пустой body (медиа без подписи и т.п.) даёт хотя бы подпись — иначе whatsapp-service
     * отклоняет POST /send-message (`!message` в JS).
     */
    private function forwardOutboundTextBody(User $user, Message $message): string
    {
        $raw = (string) ($message->body ?? '');
        if (trim($raw) !== '') {
            return OperatorSignature::prepend($user, $raw);
        }

        $type = $message->type ?: 'chat';

        return $type !== 'chat'
            ? OperatorSignature::prepend($user, MediaType::previewText($type, null))
            : OperatorSignature::prepend($user, '');
    }

    public function forward(Request $request, Message $message): JsonResponse
    {
        $user = $request->user();
        $this->authorize('view', $message->chat);

        $data = $request->validate([
            'contact_ids' => ['required', 'array', 'min:1'],
            'contact_ids.*' => ['integer', 'exists:contacts,id'],
            'whatsapp_session_id' => ['required', 'integer', 'exists:whatsapp_sessions,id'],
        ]);

        $session = WhatsappSession::findOrFail((int) $data['whatsapp_session_id']);
        abort_unless($user->can('use', $session), 403, 'Этот номер WhatsApp вам не назначен.');

        $contacts = Contact::query()
            ->whereIn('id', array_values(array_unique(array_map('intval', $data['contact_ids']))))
            ->get();

        if ($contacts->isEmpty()) {
            return response()->json(['success' => false, 'error' => 'Контакты не найдены.'], 422);
        }

        $message->loadMissing(['media']);

        $sent = 0;

        foreach ($contacts as $contact) {
            $chat = $this->chatService->findForwardTargetChatForContact($contact, $session);

            $outboundBody = $this->forwardOutboundTextBody($user, $message);

            $forward = Message::create([
                'chat_id' => $chat->id,
                'whatsapp_session_id' => $session->id,
                'whatsapp_message_id' => null,
                'direction' => 'outbound',
                'type' => $message->type ?: 'chat',
                'body' => $outboundBody,
                'sent_by_user_id' => $user->id,
                'sender_name' => $user->name,
                'is_forwarded' => true,
                'quoted_message_id' => null,
                'ack' => 'pending',
                'message_timestamp' => now(),
            ]);

            $payloadType = 'text';
            $payload = ['body' => $outboundBody, 'quoted_message_id' => null];

            /** @var Collection<int, MessageMedia> $media */
            $media = $message->media ?? collect();
            if ($media->isNotEmpty()) {
                foreach ($media as $m) {
                    MessageMedia::create([
                        'message_id' => $forward->id,
                        'mime_type' => $m->mime_type,
                        'filename' => $m->filename,
                        'disk_path' => $m->disk_path,
                        'file_size' => $m->file_size,
                    ]);
                }
                $payloadType = 'media';
                $first = $media->first();
                $captionRaw = trim((string) ($message->body ?? ''));
                $caption = $captionRaw !== ''
                    ? OperatorSignature::prepend($user, (string) $message->body)
                    : OperatorSignature::prepend($user, MediaType::previewText($message->type ?: 'chat', null));
                $payload = [
                    'disk' => 'local',
                    'path' => (string) ($first?->disk_path ?? ''),
                    'mimetype' => (string) ($first?->mime_type ?? 'application/octet-stream'),
                    'filename' => $first?->filename,
                    'caption' => $caption,
                ];
            }

            SendOutboundMessageJob::dispatch($forward->id, $payloadType, $payload);
            $this->chatService->refreshChatLastMessageSnapshot($chat);
            $sent++;
        }

        return response()->json(['success' => true, 'sent' => $sent]);
    }

    public function forwardBulk(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'message_ids' => ['required', 'array', 'min:1'],
            'message_ids.*' => ['integer', 'exists:messages,id'],
            'contact_ids' => ['required', 'array', 'min:1'],
            'contact_ids.*' => ['integer', 'exists:contacts,id'],
            'whatsapp_session_id' => ['required', 'integer', 'exists:whatsapp_sessions,id'],
        ]);

        $messageIds = array_values(array_unique(array_map('intval', $data['message_ids'])));
        $messages = Message::query()
            ->whereIn('id', $messageIds)
            ->with(['media', 'chat'])
            ->orderBy('id')
            ->get();

        if ($messages->isEmpty()) {
            return response()->json(['success' => false, 'error' => 'Сообщения не найдены.'], 422);
        }

        $chatId = (int) $messages->first()->chat_id;
        if ($messages->contains(fn (Message $m) => (int) $m->chat_id !== $chatId)) {
            return response()->json(['success' => false, 'error' => 'Можно пересылать только сообщения из одного чата.'], 422);
        }

        $this->authorize('view', $messages->first()->chat);

        $session = WhatsappSession::findOrFail((int) $data['whatsapp_session_id']);
        abort_unless($user->can('use', $session), 403, 'Этот номер WhatsApp вам не назначен.');

        $contacts = Contact::query()
            ->whereIn('id', array_values(array_unique(array_map('intval', $data['contact_ids']))))
            ->get();

        if ($contacts->isEmpty()) {
            return response()->json(['success' => false, 'error' => 'Контакты не найдены.'], 422);
        }

        $sent = 0;

        foreach ($contacts as $contact) {
            $chat = $this->chatService->findForwardTargetChatForContact($contact, $session);

            foreach ($messages as $src) {
                $outboundBody = $this->forwardOutboundTextBody($user, $src);

                $forward = Message::create([
                    'chat_id' => $chat->id,
                    'whatsapp_session_id' => $session->id,
                    'whatsapp_message_id' => null,
                    'direction' => 'outbound',
                    'type' => $src->type ?: 'chat',
                    'body' => $outboundBody,
                    'sent_by_user_id' => $user->id,
                    'sender_name' => $user->name,
                    'is_forwarded' => true,
                    'quoted_message_id' => null,
                    'ack' => 'pending',
                    'message_timestamp' => now(),
                ]);

                $payloadType = 'text';
                $payload = ['body' => $outboundBody, 'quoted_message_id' => null];

                $media = $src->media ?? collect();
                if ($media->isNotEmpty()) {
                    foreach ($media as $m) {
                        MessageMedia::create([
                            'message_id' => $forward->id,
                            'mime_type' => $m->mime_type,
                            'filename' => $m->filename,
                            'disk_path' => $m->disk_path,
                            'file_size' => $m->file_size,
                        ]);
                    }
                    $payloadType = 'media';
                    $first = $media->first();
                    $captionRaw = trim((string) ($src->body ?? ''));
                    $caption = $captionRaw !== ''
                        ? OperatorSignature::prepend($user, (string) $src->body)
                        : OperatorSignature::prepend($user, MediaType::previewText($src->type ?: 'chat', null));
                    $payload = [
                        'disk' => 'local',
                        'path' => (string) ($first?->disk_path ?? ''),
                        'mimetype' => (string) ($first?->mime_type ?? 'application/octet-stream'),
                        'filename' => $first?->filename,
                        'caption' => $caption,
                    ];
                }

                SendOutboundMessageJob::dispatch($forward->id, $payloadType, $payload);
                $sent++;
            }

            $this->chatService->refreshChatLastMessageSnapshot($chat);
        }

        return response()->json(['success' => true, 'sent' => $sent]);
    }
}
