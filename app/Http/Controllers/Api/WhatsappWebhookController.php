<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Events\ChatsListNotify;
use App\Events\MessageAckUpdated;
use App\Events\MessageReactionsUpdated;
use App\Events\NewMessageReceived;
use App\Events\WhatsappStatusChanged;
use App\Http\Controllers\Controller;
use App\Jobs\ProcessWhatsappCallRejectedJob;
use App\Jobs\ProcessWhatsappInboundJob;
use App\Jobs\ReinitializeWhatsappSessionJob;
use App\Models\Chat;
use App\Models\Message;
use App\Models\MessageMedia;
use App\Models\MessageReaction;
use App\Models\WhatsappSession;
use App\Support\ChatBroadcastAudience;
use App\Support\SafeBroadcast;
use App\Support\WhatsappSessionResolver;
use App\Support\TranscribeAudioJobDispatcher;
use App\Services\Whatsapp\WhatsappSessionLogoutAlertService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

final class WhatsappWebhookController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        $event = $request->input('event');
        $data = $request->input('data', []);

        return match ($event) {
            'message_received' => $this->onMessageReceived($data),
            'call_incoming' => $this->onCallIncoming($data),
            'connected' => $this->onConnected($data),
            'disconnected' => $this->onDisconnected($data),
            'qr_generated' => $this->onQrGenerated($data),
            'auth_failure' => $this->onAuthFailure($data),
            'message_status' => $this->onMessageStatus($data),
            'message_reaction' => $this->onMessageReaction($data),
            default => response()->json(['status' => 'ignored']),
        };
    }

    public function attachInboundMedia(Request $request): JsonResponse
    {
        $expected = (string) config('services.whatsapp.service_token', '');
        $provided = (string) $request->bearerToken();
        if ($expected === '' || ! hash_equals($expected, $provided)) {
            abort(401);
        }

        $allowedMimes = config('accel.inbound_media_mimetypes', []);

        $validated = $request->validate([
            'session' => ['required', 'string', 'max:128'],
            'messageId' => ['required', 'string', 'max:191'],
            'mimetype' => ['nullable', 'string', 'max:255'],
            'file' => [
                'required',
                'file',
                'max:102400',
                'mimetypes:'.implode(',', is_array($allowedMimes) ? $allowedMimes : []),
            ],
        ]);

        $companyId = isset($validated['companyId']) ? (int) $validated['companyId'] : null;
        $session = WhatsappSessionResolver::resolveByName($validated['session'], $companyId);
        if ($session === null) {
            return response()->json(['status' => 'session_not_found'], 404);
        }

        /** @var Message|null $message */
        $message = Message::query()
            ->where('whatsapp_message_id', $validated['messageId'])
            ->where('whatsapp_session_id', $session->id)
            ->first();

        if ($message === null) {
            return response()->json(['status' => 'message_not_found', 'retry' => true], 404);
        }

        if ($message->media()->exists()) {
            TranscribeAudioJobDispatcher::dispatchIfNeeded($message);

            return response()->json(['status' => 'ok', 'duplicate' => true]);
        }

        $upload = $request->file('file');
        $mime = (string) ($validated['mimetype'] ?: ($upload->getMimeType() ?? 'application/octet-stream'));
        $mime = strtolower(explode(';', $mime)[0]);
        $originalName = (string) ($upload->getClientOriginalName() ?: 'media');
        $storedPath = $upload->store('whatsapp-media/'.date('Y/m'), 'local');

        MessageMedia::create([
            'message_id' => $message->id,
            'mime_type' => $mime !== '' ? $mime : 'application/octet-stream',
            'filename' => $originalName,
            'disk_path' => $storedPath,
            'file_size' => $upload->getSize() ?: 0,
        ]);

        $message->load([
            'media',
            'sentByUser',
            'whatsappSession',
            'reactions.user:id,name',
            'quotedMessage:id,whatsapp_message_id,direction,type,body,sender_name,sender_phone,sent_by_user_id',
            'quotedMessage.sentByUser:id,name',
            'quotedMessage.media:id,message_id,mime_type,filename',
        ]);

        SafeBroadcast::dispatch(new NewMessageReceived($message, $message->chat_id), 'whatsapp-inbound-media');

        TranscribeAudioJobDispatcher::dispatchIfNeeded($message);

        return response()->json(['status' => 'ok']);
    }

    private function onMessageReceived(array $data): JsonResponse
    {
        ProcessWhatsappInboundJob::dispatch($data);

        return response()->json(['status' => 'queued']);
    }

    private function onCallIncoming(array $data): JsonResponse
    {
        $this->broadcastIncomingCallNotification($data);
        ProcessWhatsappCallRejectedJob::dispatch($data);

        return response()->json(['status' => 'queued']);
    }

    /**
     * Мгновенное уведомление в открытом веб-клиенте (Echo + Web Notifications), пока вкладка в фоне.
     *
     * @param  array<string, mixed>  $data
     */
    private function broadcastIncomingCallNotification(array $data): void
    {
        $fromMe = filter_var($data['fromMe'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if ($fromMe) {
            return;
        }

        $sessionName = trim((string) ($data['session'] ?? ''));
        $peerJid = trim((string) ($data['peerJid'] ?? ''));
        if ($sessionName === '' || $peerJid === '') {
            return;
        }

        $companyId = isset($data['companyId']) ? (int) $data['companyId'] : null;
        $session = WhatsappSessionResolver::resolveByName($sessionName, $companyId);
        if ($session === null) {
            return;
        }

        $chat = Chat::query()
            ->where('whatsapp_session_id', $session->id)
            ->where('whatsapp_chat_id', $peerJid)
            ->first();

        if ($chat === null) {
            return;
        }

        $recipientUserIds = ChatBroadcastAudience::userIdsWithAccessToChat($chat);
        if ($recipientUserIds === []) {
            return;
        }

        $chat->loadMissing(['contact', 'whatsappSession']);

        SafeBroadcast::dispatch(new ChatsListNotify(
            $chat->id,
            'call_incoming',
            'Входящий звонок WhatsApp',
            ChatBroadcastAudience::chatDisplayName($chat),
            ChatBroadcastAudience::absoluteIconUrl($chat->contact?->profile_picture_url),
            (bool) $chat->is_muted,
            $recipientUserIds,
        ), 'whatsapp-call');
    }

    private function onConnected(array $data): JsonResponse
    {
        $sessionName = $data['session'] ?? 'default';
        $phone = isset($data['phone']) ? (string) $data['phone'] : null;
        $waName = isset($data['name']) ? (string) $data['name'] : null;
        $platform = isset($data['platform']) ? (string) $data['platform'] : null;

        $companyId = isset($data['companyId']) ? (int) $data['companyId'] : null;
        $session = WhatsappSessionResolver::resolveByName((string) $sessionName, $companyId);
        if ($session) {
            $update = [
                'status' => 'connected',
                'connected_at' => now(),
                'qr_required_at' => null,
            ];
            if ($phone !== null) {
                $update['phone_number'] = $phone;
            }
            if ($waName !== null) {
                $update['wa_name'] = $waName;
            }
            if ($platform !== null && $platform !== '') {
                $update['wa_platform'] = $platform;
            }
            $session->update($update);
        }

        SafeBroadcast::dispatch(new WhatsappStatusChanged($sessionName, 'connected', $phone, $waName), 'whatsapp-status');

        return response()->json(['status' => 'ok']);
    }

    private function onDisconnected(array $data): JsonResponse
    {
        $sessionName = $data['session'] ?? 'default';
        $reason = isset($data['reason']) ? (string) $data['reason'] : null;

        // Это «авто-disconnect» от whatsapp-web.js: меняем только фактический
        // статус, но НЕ трогаем desired_state — пользователь не просил
        // отключать, поэтому watchdog должен поднять сессию заново.
        $companyId = isset($data['companyId']) ? (int) $data['companyId'] : null;
        $session = WhatsappSessionResolver::resolveByName($sessionName, $companyId);
        if ($session !== null) {
            $session->update([
                'status' => 'disconnected',
                'disconnected_at' => now(),
                'last_disconnect_reason' => $reason,
            ]);

            Log::warning('WhatsApp session disconnected', [
                'company_id' => $session->company_id,
                'session' => $sessionName,
                'reason' => $reason,
            ]);

            app(WhatsappSessionLogoutAlertService::class)->notify($session, $reason);

            if ($session->desired_state === WhatsappSession::DESIRED_ACTIVE) {
                ReinitializeWhatsappSessionJob::dispatch($session->id)
                    ->delay(now()->addSeconds(8));
            }
        } else {
            WhatsappSession::query()
                ->withoutGlobalScope('tenant')
                ->where('session_name', $sessionName)
                ->update([
                    'status' => 'disconnected',
                    'disconnected_at' => now(),
                    'last_disconnect_reason' => $reason,
                ]);

            Log::warning('WhatsApp session disconnected (session resolved without tenant scope)', [
                'session' => $sessionName,
                'reason' => $reason,
            ]);
        }

        SafeBroadcast::dispatch(new WhatsappStatusChanged($sessionName, 'disconnected'), 'whatsapp-status');

        return response()->json(['status' => 'ok']);
    }

    private function onQrGenerated(array $data): JsonResponse
    {
        $sessionName = $data['session'] ?? 'default';

        $companyId = isset($data['companyId']) ? (int) $data['companyId'] : null;
        $session = WhatsappSessionResolver::resolveByName($sessionName, $companyId);
        if ($session !== null) {
            $wasConnected = $session->status === 'connected';
            $update = ['status' => 'qr_pending'];
            if ($wasConnected || $session->qr_required_at === null) {
                $update['qr_required_at'] = $session->qr_required_at ?? now();
                if ($wasConnected) {
                    $update['disconnected_at'] = now();
                }
            }
            $session->update($update);

            if ($wasConnected) {
                Log::warning('WhatsApp session requires QR after being connected', [
                    'company_id' => $session->company_id,
                    'session' => $sessionName,
                    'last_disconnect_reason' => $session->last_disconnect_reason,
                ]);
            }
        } else {
            WhatsappSession::query()
                ->withoutGlobalScope('tenant')
                ->where('session_name', $sessionName)
                ->update(['status' => 'qr_pending']);
        }

        SafeBroadcast::dispatch(new WhatsappStatusChanged($sessionName, 'qr_pending'), 'whatsapp-status');

        return response()->json(['status' => 'ok']);
    }

    private function onAuthFailure(array $data): JsonResponse
    {
        $sessionName = $data['session'] ?? 'default';
        $message = isset($data['message']) ? (string) $data['message'] : null;

        $companyId = isset($data['companyId']) ? (int) $data['companyId'] : null;
        $session = WhatsappSessionResolver::resolveByName($sessionName, $companyId);
        if ($session !== null) {
            $session->update([
                'status' => 'disconnected',
                'disconnected_at' => now(),
                'last_auth_failure_message' => $message,
            ]);

            Log::warning('WhatsApp session auth failure', [
                'company_id' => $session->company_id,
                'session' => $sessionName,
                'message' => $message,
            ]);

            if ($session->desired_state === WhatsappSession::DESIRED_ACTIVE) {
                ReinitializeWhatsappSessionJob::dispatch($session->id)
                    ->delay(now()->addSeconds(8));
            }
        } else {
            WhatsappSession::query()
                ->withoutGlobalScope('tenant')
                ->where('session_name', $sessionName)
                ->update([
                    'status' => 'disconnected',
                    'disconnected_at' => now(),
                    'last_auth_failure_message' => $message,
                ]);
        }

        SafeBroadcast::dispatch(new WhatsappStatusChanged($sessionName, 'disconnected'), 'whatsapp-status');

        return response()->json(['status' => 'ok']);
    }

    private function onMessageStatus(array $data): JsonResponse
    {
        if (empty($data['messageId'])) {
            return response()->json(['status' => 'ok']);
        }

        $newAck = (string) ($data['ack'] ?? 'pending');

        $companyId = isset($data['companyId']) ? (int) $data['companyId'] : null;
        $sessionName = trim((string) ($data['session'] ?? ''));

        $messageQuery = Message::query()->where('whatsapp_message_id', $data['messageId']);
        if ($sessionName !== '') {
            $session = WhatsappSessionResolver::resolveByName($sessionName, $companyId);
            if ($session !== null) {
                $messageQuery->where('whatsapp_session_id', $session->id);
            }
        }

        /** @var Message|null $message */
        $message = $messageQuery->first();
        if ($message === null) {
            return response()->json(['status' => 'ok']);
        }

        // WhatsApp иногда присылает события «в обратном порядке» (read пришёл
        // раньше delivered из-за ретраев). Держим строгую монотонность,
        // чтобы галочка не откатывалась обратно.
        $rank = ['pending' => 0, 'failed' => 0, 'sent' => 1, 'delivered' => 2, 'read' => 3];
        $currentRank = $rank[$message->ack] ?? 0;
        $newRank = $rank[$newAck] ?? 0;

        if ($newRank <= $currentRank) {
            return response()->json(['status' => 'ok']);
        }

        $message->forceFill(['ack' => $newAck])->save();

        SafeBroadcast::dispatch(new MessageAckUpdated($message->chat_id, $message->id, $newAck), 'whatsapp-ack');

        return response()->json(['status' => 'ok']);
    }

    private function onMessageReaction(array $data): JsonResponse
    {
        if (! empty($data['fromMe'])) {
            return response()->json(['status' => 'ok']);
        }

        $messageId = (string) ($data['messageId'] ?? '');
        $senderId = (string) ($data['senderId'] ?? '');
        $reaction = (string) ($data['reaction'] ?? '');

        if ($messageId === '' || $senderId === '') {
            return response()->json(['status' => 'ok']);
        }

        $companyId = isset($data['companyId']) ? (int) $data['companyId'] : null;
        $sessionName = trim((string) ($data['session'] ?? ''));

        $messageQuery = Message::query()->where('whatsapp_message_id', $messageId);
        if ($sessionName !== '') {
            $session = WhatsappSessionResolver::resolveByName($sessionName, $companyId);
            if ($session !== null) {
                $messageQuery->where('whatsapp_session_id', $session->id);
            }
        }

        /** @var Message|null $message */
        $message = $messageQuery->first();
        if ($message === null) {
            return response()->json(['status' => 'ok']);
        }

        if ($reaction === '') {
            MessageReaction::where('message_id', $message->id)
                ->where('external_id', $senderId)
                ->delete();
        } else {
            MessageReaction::updateOrCreate(
                ['message_id' => $message->id, 'external_id' => $senderId],
                [
                    'user_id' => null,
                    'external_name' => $this->externalReactionName($senderId),
                    'emoji' => $reaction,
                ],
            );
        }

        $reactions = MessageReaction::with('user:id,name')
            ->where('message_id', $message->id)
            ->get();

        SafeBroadcast::dispatch(new MessageReactionsUpdated($message->chat_id, $message->id, $reactions), 'whatsapp-reaction');

        return response()->json(['status' => 'ok']);
    }

    private function externalReactionName(string $senderId): string
    {
        $phone = preg_replace('/\D/', '', explode('@', $senderId)[0]) ?: null;

        return $phone ?: 'Клиент';
    }
}
