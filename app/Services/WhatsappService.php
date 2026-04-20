<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Http;

final class WhatsappService
{
    private readonly string $baseUrl;
    private readonly string $token;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('services.whatsapp.url', 'http://127.0.0.1:3050'), '/');
        $this->token = (string) config('services.whatsapp.token', '');
    }

    /** @return array<string, mixed> */
    public function initializeSession(string $sessionName): array
    {
        return $this->post("/api/sessions/{$sessionName}/initialize");
    }

    /** @return array<string, mixed> */
    public function getSessionQR(string $sessionName): array
    {
        return $this->get("/api/sessions/{$sessionName}/qr");
    }

    /** @return array<string, mixed> */
    public function getSessionStatus(string $sessionName): array
    {
        return $this->get("/api/sessions/{$sessionName}/status");
    }

    /** @return array<string, mixed> */
    public function getAllSessions(): array
    {
        return $this->get('/api/sessions');
    }

    /** @return array<string, mixed> */
    public function logoutSession(string $sessionName): array
    {
        return $this->post("/api/sessions/{$sessionName}/logout");
    }

    /** @return array<string, mixed> */
    public function destroySession(string $sessionName): array
    {
        return $this->post("/api/sessions/{$sessionName}/destroy");
    }

    /** @return array<string, mixed> */
    public function sendMessage(string $sessionName, string $to, string $message, ?string $quotedMessageId = null): array
    {
        $payload = ['to' => $to, 'message' => $message];
        if ($quotedMessageId) {
            $payload['quotedMessageId'] = $quotedMessageId;
        }

        return $this->post('/api/send-message', $payload, $sessionName);
    }

    /** @return array<string, mixed> */
    public function sendMedia(string $sessionName, string $to, string $mediaData, string $mimetype, ?string $filename = null, ?string $caption = null): array
    {
        return $this->post('/api/send-media', [
            'to' => $to,
            'mediaData' => $mediaData,
            'mimetype' => $mimetype,
            'filename' => $filename,
            'caption' => $caption,
        ], $sessionName);
    }

    /** @return array<string, mixed> */
    public function sendSeen(string $sessionName, string $chatId): array
    {
        return $this->post('/api/send-seen', ['chatId' => $chatId], $sessionName);
    }

    /** @return array<string, mixed> */
    public function setTyping(string $sessionName, string $chatId, bool $isTyping): array
    {
        return $this->post('/api/set-typing', ['chatId' => $chatId, 'isTyping' => $isTyping], $sessionName);
    }

    /** @return array<string, mixed> */
    private function get(string $path): array
    {
        try {
            $response = Http::withToken($this->token)
                ->timeout(30)
                ->get($this->baseUrl . $path);

            return $response->json() ?: [];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /** @return array<string, mixed> */
    private function post(string $path, array $data = [], ?string $sessionHeader = null): array
    {
        try {
            $request = Http::withToken($this->token)->timeout(30);

            if ($sessionHeader) {
                $request = $request->withHeaders(['X-WhatsApp-Session' => $sessionHeader]);
            }

            $response = $request->post($this->baseUrl . $path, $data);

            return $response->json() ?: [];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}
