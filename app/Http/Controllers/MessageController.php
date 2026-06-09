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
use App\Services\CrossChannelMessageShareService;
use App\Services\WhatsappService;
use App\Support\MediaType;
use App\Support\OperatorSignature;
use App\Support\OutboundSenderDisplayName;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

final class MessageController extends Controller
{
    public function __construct(
        private readonly ChatService $chatService,
        private readonly CrossChannelMessageShareService $crossChannelShare,
    ) {}

    public function react(Request $request, Message $message, WhatsappService $whatsappService): JsonResponse
    {
        $message->loadMissing('chat');
        if ($message->chat === null) {
            abort(404);
        }

        $this->authorize('view', $message->chat);

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
        $message->loadMissing('chat');
        if ($message->chat === null) {
            abort(404);
        }

        $this->authorize('view', $message->chat);

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

    public function retry(Request $request, Message $message): JsonResponse
    {
        $message->loadMissing(['chat', 'whatsappSession', 'media']);
        if ($message->chat === null) {
            abort(404);
        }

        $this->authorize('view', $message->chat);

        $user = $request->user();

        if ($message->direction !== 'outbound') {
            return response()->json(['success' => false, 'error' => 'Повторить можно только исходящие сообщения.'], 422);
        }

        if (! $user->hasRole('administrator') && (int) $message->sent_by_user_id !== (int) $user->id) {
            abort(403);
        }

        if (! in_array($message->ack, ['pending', 'failed'], true)) {
            return response()->json(['success' => false, 'error' => 'Сообщение уже доставлено или не требует повторной отправки.'], 422);
        }

        $session = $message->whatsappSession;
        if ($session === null) {
            return response()->json(['success' => false, 'error' => 'У сообщения нет сессии WhatsApp.'], 422);
        }

        abort_unless($user->can('use', $session), 403, 'Этот номер WhatsApp вам не назначен.');

        $resolved = $this->buildRetryOutboundPayload($message);
        if ($resolved === null) {
            return response()->json(['success' => false, 'error' => 'Не удалось восстановить данные для повторной отправки.'], 422);
        }

        [$payloadType, $payload] = $resolved;

        $message->forceFill([
            'ack' => 'pending',
            'whatsapp_message_id' => null,
        ])->save();

        SendOutboundMessageJob::dispatch($message->id, $payloadType, $payload);

        return response()->json(['success' => true]);
    }

    /**
     * Восстанавливает тип и payload для {@see SendOutboundMessageJob} по сохранённому сообщению.
     *
     * @return array{0: string, 1: array<string, mixed>}|null
     */
    private function buildRetryOutboundPayload(Message $message): ?array
    {
        $type = $message->type ?: 'chat';
        $meta = is_array($message->metadata) ? $message->metadata : [];

        if ($type === 'poll') {
            $poll = $meta['poll'] ?? null;
            if (! is_array($poll)) {
                return null;
            }
            $question = trim((string) ($poll['question'] ?? ''));
            $optionsRaw = $poll['options'] ?? [];
            if ($question === '' || ! is_array($optionsRaw)) {
                return null;
            }
            $options = array_values(array_filter(
                array_map(static fn ($o) => trim((string) $o), $optionsRaw),
                static fn (string $o) => $o !== '',
            ));
            if (count($options) < 2) {
                return null;
            }
            $allowMultiple = (bool) ($poll['allow_multiple_answers'] ?? false);

            return [
                'poll',
                [
                    'question' => $question,
                    'options' => $options,
                    'allow_multiple' => $allowMultiple,
                ],
            ];
        }

        if ($type === 'contact') {
            $contact = $meta['contact'] ?? null;
            if (! is_array($contact)) {
                return null;
            }
            $vcard = trim((string) ($contact['vcard'] ?? ''));
            if ($vcard === '') {
                return null;
            }
            $displayName = trim((string) ($contact['name'] ?? ''));

            return [
                'contact',
                [
                    'vcard' => $vcard,
                    'display_name' => $displayName !== '' ? $displayName : null,
                ],
            ];
        }

        $forwardSource = trim((string) ($meta['forward_source_whatsapp_message_id'] ?? ''));
        if ($forwardSource !== '') {
            return ['forward', ['source_whatsapp_message_id' => $forwardSource]];
        }

        $message->loadMissing('media');
        /** @var Collection<int, MessageMedia> $media */
        $media = $message->media ?? collect();
        if ($media->isNotEmpty()) {
            $first = $media->first();
            if ($first === null || trim((string) $first->disk_path) === '') {
                return null;
            }
            $captionRaw = trim((string) ($message->body ?? ''));
            $caption = $type === 'voice'
                ? null
                : ($captionRaw !== '' ? (string) $message->body : null);

            return [
                'media',
                [
                    'disk' => 'local',
                    'path' => (string) $first->disk_path,
                    'mimetype' => (string) ($first->mime_type ?: 'application/octet-stream'),
                    'filename' => $first->filename,
                    'caption' => $caption,
                ],
            ];
        }

        if ($message->is_forwarded && $type !== 'chat') {
            return null;
        }

        $body = trim((string) ($message->body ?? ''));
        if ($body === '') {
            return null;
        }

        $quotedRaw = $message->quoted_message_id;
        $quotedId = is_string($quotedRaw) && $quotedRaw !== '' ? $quotedRaw : null;

        $mentions = [];
        if (isset($meta['mentions']) && is_array($meta['mentions'])) {
            foreach ($meta['mentions'] as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $id = isset($row['id']) ? trim((string) $row['id']) : '';
                if ($id !== '') {
                    $mentions[] = $id;
                }
            }
        }

        return [
            'text',
            [
                'body' => (string) $message->body,
                'quoted_message_id' => $quotedId,
                'mentions' => $mentions,
            ],
        ];
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

            $sourceWhatsappMessageId = trim((string) ($message->whatsapp_message_id ?? ''));

            $forward = Message::create([
                'chat_id' => $chat->id,
                'whatsapp_session_id' => $session->id,
                'whatsapp_message_id' => null,
                'direction' => 'outbound',
                'type' => $message->type ?: 'chat',
                'body' => $outboundBody,
                'metadata' => $sourceWhatsappMessageId !== ''
                    ? ['forward_source_whatsapp_message_id' => $sourceWhatsappMessageId]
                    : null,
                'sent_by_user_id' => $user->id,
                'sender_name' => OutboundSenderDisplayName::resolve($user),
                'is_forwarded' => true,
                'quoted_message_id' => null,
                'ack' => 'pending',
                'message_timestamp' => now(),
            ]);

            $payloadType = $sourceWhatsappMessageId !== '' ? 'forward' : 'text';
            $payload = $sourceWhatsappMessageId !== ''
                ? ['source_whatsapp_message_id' => $sourceWhatsappMessageId]
                : ['body' => $outboundBody, 'quoted_message_id' => null];

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
                if ($sourceWhatsappMessageId === '') {
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
            }

            SendOutboundMessageJob::dispatch($forward->id, $payloadType, $payload);
            $this->chatService->refreshChatLastMessageSnapshot($chat);
            $sent++;
        }

        return response()->json(['success' => true, 'sent' => $sent]);
    }

    public function shareToTeam(Request $request, Message $message): JsonResponse
    {
        $user = $request->user();
        $message->loadMissing('chat');
        if ($message->chat === null) {
            abort(404);
        }
        $this->authorize('view', $message->chat);

        $data = $request->validate([
            'team_conversation_ids' => ['required', 'array', 'min:1'],
            'team_conversation_ids.*' => ['integer', 'exists:team_conversations,id'],
            'body' => ['nullable', 'string', 'max:16000'],
        ]);

        $result = $this->crossChannelShare->shareWhatsappMessageToTeam(
            $user,
            $message,
            array_map('intval', $data['team_conversation_ids']),
            trim((string) ($data['body'] ?? '')),
        );

        return response()->json([
            'success' => true,
            'sent' => $result['sent'],
        ]);
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

                $sourceWhatsappMessageId = trim((string) ($src->whatsapp_message_id ?? ''));

                $forward = Message::create([
                    'chat_id' => $chat->id,
                    'whatsapp_session_id' => $session->id,
                    'whatsapp_message_id' => null,
                    'direction' => 'outbound',
                    'type' => $src->type ?: 'chat',
                    'body' => $outboundBody,
                    'metadata' => $sourceWhatsappMessageId !== ''
                        ? ['forward_source_whatsapp_message_id' => $sourceWhatsappMessageId]
                        : null,
                    'sent_by_user_id' => $user->id,
                    'sender_name' => OutboundSenderDisplayName::resolve($user),
                    'is_forwarded' => true,
                    'quoted_message_id' => null,
                    'ack' => 'pending',
                    'message_timestamp' => now(),
                ]);

                $payloadType = $sourceWhatsappMessageId !== '' ? 'forward' : 'text';
                $payload = $sourceWhatsappMessageId !== ''
                    ? ['source_whatsapp_message_id' => $sourceWhatsappMessageId]
                    : ['body' => $outboundBody, 'quoted_message_id' => null];

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
                    if ($sourceWhatsappMessageId === '') {
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
                }

                SendOutboundMessageJob::dispatch($forward->id, $payloadType, $payload);
                $sent++;
            }

            $this->chatService->refreshChatLastMessageSnapshot($chat);
        }

        return response()->json(['success' => true, 'sent' => $sent]);
    }
}
