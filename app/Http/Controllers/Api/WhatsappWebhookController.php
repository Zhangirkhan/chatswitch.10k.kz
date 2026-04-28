<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Events\MessageAckUpdated;
use App\Events\MessageReactionsUpdated;
use App\Events\WhatsappStatusChanged;
use App\Http\Controllers\Controller;
use App\Jobs\ProcessWhatsappInboundJob;
use App\Models\Message;
use App\Models\MessageReaction;
use App\Models\WhatsappSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class WhatsappWebhookController extends Controller
{

    public function handle(Request $request): JsonResponse
    {
        $event = $request->input('event');
        $data = $request->input('data', []);

        return match ($event) {
            'message_received' => $this->onMessageReceived($data),
            'connected' => $this->onConnected($data),
            'disconnected' => $this->onDisconnected($data),
            'qr_generated' => $this->onQrGenerated($data),
            'auth_failure' => $this->onAuthFailure($data),
            'message_status' => $this->onMessageStatus($data),
            'message_reaction' => $this->onMessageReaction($data),
            default => response()->json(['status' => 'ignored']),
        };
    }

    private function onMessageReceived(array $data): JsonResponse
    {
        ProcessWhatsappInboundJob::dispatch($data);

        return response()->json(['status' => 'queued']);
    }

    private function onConnected(array $data): JsonResponse
    {
        $sessionName = $data['session'] ?? 'default';
        $phone = isset($data['phone']) ? (string) $data['phone'] : null;
        $waName = isset($data['name']) ? (string) $data['name'] : null;
        $platform = isset($data['platform']) ? (string) $data['platform'] : null;

        $session = WhatsappSession::where('session_name', $sessionName)->first();
        if ($session) {
            $update = [
                'status' => 'connected',
                'connected_at' => now(),
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

        broadcast(new WhatsappStatusChanged($sessionName, 'connected', $phone, $waName));

        return response()->json(['status' => 'ok']);
    }

    private function onDisconnected(array $data): JsonResponse
    {
        $sessionName = $data['session'] ?? 'default';

        // Это «авто-disconnect» от whatsapp-web.js: меняем только фактический
        // статус, но НЕ трогаем desired_state — пользователь не просил
        // отключать, поэтому watchdog должен поднять сессию заново.
        WhatsappSession::where('session_name', $sessionName)->update([
            'status' => 'disconnected',
            'disconnected_at' => now(),
        ]);

        broadcast(new WhatsappStatusChanged($sessionName, 'disconnected'));

        return response()->json(['status' => 'ok']);
    }

    private function onQrGenerated(array $data): JsonResponse
    {
        $sessionName = $data['session'] ?? 'default';

        WhatsappSession::where('session_name', $sessionName)->update([
            'status' => 'qr_pending',
        ]);

        broadcast(new WhatsappStatusChanged($sessionName, 'qr_pending'));

        return response()->json(['status' => 'ok']);
    }

    private function onAuthFailure(array $data): JsonResponse
    {
        $sessionName = $data['session'] ?? 'default';

        WhatsappSession::where('session_name', $sessionName)->update([
            'status' => 'disconnected',
        ]);

        broadcast(new WhatsappStatusChanged($sessionName, 'auth_failure'));

        return response()->json(['status' => 'ok']);
    }

    private function onMessageStatus(array $data): JsonResponse
    {
        if (empty($data['messageId'])) {
            return response()->json(['status' => 'ok']);
        }

        $newAck = (string) ($data['ack'] ?? 'pending');

        /** @var Message|null $message */
        $message = Message::where('whatsapp_message_id', $data['messageId'])->first();
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

        broadcast(new MessageAckUpdated($message->chat_id, $message->id, $newAck));

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

        /** @var Message|null $message */
        $message = Message::where('whatsapp_message_id', $messageId)->first();
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

        broadcast(new MessageReactionsUpdated($message->chat_id, $message->id, $reactions));

        return response()->json(['status' => 'ok']);
    }

    private function externalReactionName(string $senderId): string
    {
        $phone = preg_replace('/\D/', '', explode('@', $senderId)[0]) ?: null;

        return $phone ?: 'Клиент';
    }
}
