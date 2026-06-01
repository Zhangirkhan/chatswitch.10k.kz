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

    /** Проверка, что Node whatsapp-service отвечает (маршрут без токена). */
    public function healthReachable(): bool
    {
        return $this->healthPing()['ok'];
    }

    /**
     * @return array{ok: bool, latency_ms: int|null, body: array<string, mixed>|null}
     */
    public function healthPing(): array
    {
        $started = hrtime(true);
        try {
            $response = Http::timeout(3)->get($this->baseUrl.'/health');
            $ms = (int) round((hrtime(true) - $started) / 1e6);
            $body = $response->json();
            $ok = $response->successful() && (($body['status'] ?? '')) === 'ok';

            return ['ok' => $ok, 'latency_ms' => $ms, 'body' => is_array($body) ? $body : null];
        } catch (\Throwable) {
            $ms = (int) round((hrtime(true) - $started) / 1e6);

            return ['ok' => false, 'latency_ms' => $ms, 'body' => null];
        }
    }

    /**
     * @return array{result: array<string, mixed>, latency_ms: int}
     */
    public function getSessionStatusTimed(string $sessionName): array
    {
        $started = hrtime(true);
        $result = $this->getSessionStatus($sessionName);
        $ms = (int) round((hrtime(true) - $started) / 1e6);

        return ['result' => $result, 'latency_ms' => $ms];
    }

    /**
     * @param  array<string, mixed>  $initializeResponse
     */
    public function initializeAccepted(array $initializeResponse): bool
    {
        return ($initializeResponse['success'] ?? false) === true;
    }

    /** @return array<string, mixed> */
    public function initializeSession(string $sessionName, ?int $companyId = null): array
    {
        $payload = [];
        if ($companyId !== null && $companyId > 0) {
            $payload['companyId'] = $companyId;
        }

        return $this->post("/api/sessions/{$sessionName}/initialize", $payload);
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

    /**
     * Активная проверка «живо ли подключение»: ходит в puppeteer-клиент
     * whatsapp-web.js, спрашивает его реальное состояние и статус браузера.
     *
     * @return array<string, mixed>
     */
    public function verifySession(string $sessionName): array
    {
        return $this->get("/api/sessions/{$sessionName}/verify", timeoutSeconds: 8);
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
    public function sendMessage(
        string $sessionName,
        string $to,
        string $message,
        ?string $quotedMessageId = null,
        array $mentions = [],
    ): array
    {
        $payload = ['to' => $to, 'message' => $message];
        if ($quotedMessageId) {
            $payload['quotedMessageId'] = $quotedMessageId;
        }

        if ($mentions !== []) {
            $payload['mentions'] = array_values(array_filter(
                $mentions,
                static fn ($m) => is_string($m) && trim($m) !== '',
            ));
        }

        return $this->post('/api/send-message', $payload, $sessionName);
    }

    /** @return array<string, mixed> */
    public function forwardMessage(string $sessionName, string $to, string $sourceWhatsappMessageId): array
    {
        return $this->post('/api/forward-message', [
            'to' => $to,
            'sourceMessageId' => $sourceWhatsappMessageId,
        ], $sessionName);
    }

    /** @return array<string, mixed> */
    public function reactToMessage(string $sessionName, string $messageId, string $reaction): array
    {
        return $this->post('/api/react-message', [
            'messageId' => $messageId,
            'reaction' => $reaction,
        ], $sessionName);
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

    /**
     * Стриминговая отправка медиа (без base64 в памяти Laravel).
     *
     * @return array<string, mixed>
     */
    public function sendMediaFile(
        string $sessionName,
        string $to,
        string $absolutePath,
        string $mimetype,
        ?string $filename = null,
        ?string $caption = null,
        bool $sendAsVoice = false,
    ): array {
        try {
            $stream = fopen($absolutePath, 'rb');
            if ($stream === false) {
                return ['success' => false, 'error' => 'Cannot open media file for reading.'];
            }

            $request = Http::withToken($this->token)
                ->withHeaders(['X-WhatsApp-Session' => $sessionName])
                ->timeout(120)
                ->attach('file', $stream, $filename ?: basename($absolutePath), ['Content-Type' => $mimetype]);

            $payload = array_filter([
                'to' => $to,
                'mimetype' => $mimetype,
                'filename' => $filename,
                'caption' => $caption,
                'sendAsVoice' => $sendAsVoice ? '1' : null,
            ], fn ($v) => $v !== null && $v !== '');

            $response = $request->post($this->baseUrl.'/api/send-media-upload', $payload);

            if (is_resource($stream)) {
                fclose($stream);
            }

            /** @var array<string, mixed> $json */
            $json = $response->json() ?: [];
            if (! $response->successful() && ($json['success'] ?? null) !== true) {
                $err = isset($json['error']) && is_string($json['error']) ? $json['error'] : $response->body();
                $json['error'] = trim($err.' [HTTP '.$response->status().']');
            }

            return $json;
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * @param  array<int, string>  $options
     * @return array<string, mixed>
     */
    public function sendPoll(string $sessionName, string $to, string $question, array $options, bool $allowMultipleAnswers = false): array
    {
        return $this->post('/api/send-poll', [
            'to' => $to,
            'question' => $question,
            'options' => array_values($options),
            'allowMultipleAnswers' => $allowMultipleAnswers,
        ], $sessionName);
    }

    /**
     * @param  array<int, string>  $participants  WhatsApp IDs (e.g. 77011234567@c.us)
     * @return array<string, mixed>
     */
    public function createGroup(string $sessionName, string $subject, array $participants): array
    {
        return $this->post('/api/create-group', [
            'subject' => $subject,
            'participants' => array_values($participants),
        ], $sessionName);
    }

    /** @return array<string, mixed> */
    public function getChats(string $sessionName): array
    {
        return $this->get('/api/chats', sessionHeader: $sessionName);
    }

    /** @return array<string, mixed> */
    public function getGroupParticipants(string $sessionName, string $chatId): array
    {
        $encoded = rawurlencode($chatId);

        return $this->get("/api/chats/{$encoded}/participants", sessionHeader: $sessionName);
    }

    /** @return array<string, mixed> */
    public function getChatMessages(string $sessionName, string $chatId, int $limit = 500): array
    {
        $encoded = rawurlencode($chatId);
        $limit = max(1, min(5, $limit));

        return $this->get("/api/chats/{$encoded}/messages?limit={$limit}", sessionHeader: $sessionName, timeoutSeconds: 60);
    }

    /** @return array<string, mixed> */
    public function sendContact(string $sessionName, string $to, string $vcard, ?string $displayName = null): array
    {
        return $this->post('/api/send-contact', [
            'to' => $to,
            'vcard' => $vcard,
            'displayName' => $displayName,
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
    private function get(string $path, int $timeoutSeconds = 30, ?string $sessionHeader = null): array
    {
        try {
            $request = Http::withToken($this->token)->timeout($timeoutSeconds);
            if ($sessionHeader) {
                $request = $request->withHeaders(['X-WhatsApp-Session' => $sessionHeader]);
            }
            $response = $request->get($this->baseUrl.$path);

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

            $response = $request->post($this->baseUrl.$path, $data);

            return $response->json() ?: [];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}
