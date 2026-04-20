<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Events\NewMessageReceived;
use App\Events\WhatsappStatusChanged;
use App\Http\Controllers\Controller;
use App\Models\Message;
use App\Models\WhatsappSession;
use App\Services\ChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class WhatsappWebhookController extends Controller
{
    public function __construct(
        private readonly ChatService $chatService,
    ) {}

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
            default => response()->json(['status' => 'ignored']),
        };
    }

    private function onMessageReceived(array $data): JsonResponse
    {
        $sessionName = $data['session'] ?? 'default';
        $session = WhatsappSession::where('session_name', $sessionName)->first();

        if (! $session) {
            return response()->json(['status' => 'session_not_found'], 404);
        }

        $chat = $this->chatService->findOrCreateChat($data, $session);
        $message = $this->chatService->storeInboundMessage($chat, $session, $data);
        $message->load(['media', 'sentByUser', 'whatsappSession']);

        broadcast(new NewMessageReceived($message, $chat->id));

        return response()->json(['status' => 'ok', 'message_id' => $message->id]);
    }

    private function onConnected(array $data): JsonResponse
    {
        $sessionName = $data['session'] ?? 'default';

        $session = WhatsappSession::where('session_name', $sessionName)->first();
        if ($session) {
            $session->update([
                'status' => 'connected',
                'phone_number' => $data['phone'] ?? $session->phone_number,
                'display_name' => $data['name'] ?? $session->display_name,
                'connected_at' => now(),
            ]);
        }

        broadcast(new WhatsappStatusChanged($sessionName, 'connected', $data['phone'] ?? null));

        return response()->json(['status' => 'ok']);
    }

    private function onDisconnected(array $data): JsonResponse
    {
        $sessionName = $data['session'] ?? 'default';

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
        if (! empty($data['messageId'])) {
            Message::where('whatsapp_message_id', $data['messageId'])
                ->update(['ack' => $data['ack'] ?? 'pending']);
        }

        return response()->json(['status' => 'ok']);
    }
}
