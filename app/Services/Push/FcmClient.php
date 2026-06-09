<?php

declare(strict_types=1);

namespace App\Services\Push;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final class FcmClient
{
    public function __construct(
        private readonly FcmAccessTokenProvider $tokenProvider,
    ) {}

    /**
     * @param  array<string, string>  $data
     */
    public function sendData(string $fcmToken, array $data): FcmSendResult
    {
        if (! $this->tokenProvider->isConfigured()) {
            return new FcmSendResult(success: false, error: 'fcm_not_configured');
        }

        $accessToken = $this->tokenProvider->getAccessToken();
        $projectId = $this->tokenProvider->projectId();
        if ($accessToken === null || $projectId === null) {
            return new FcmSendResult(success: false, error: 'fcm_auth_failed');
        }

        $url = 'https://fcm.googleapis.com/v1/projects/'.$projectId.'/messages:send';
        $response = Http::withToken($accessToken)
            ->acceptJson()
            ->post($url, [
                'message' => [
                    'token' => $fcmToken,
                    'data' => $data,
                    'android' => [
                        'priority' => 'high',
                    ],
                ],
            ]);

        if ($response->successful()) {
            return new FcmSendResult(success: true);
        }

        $body = $response->json();
        $errorCode = is_array($body)
            ? (string) data_get($body, 'error.details.0.errorCode', data_get($body, 'error.status', ''))
            : '';
        $message = is_array($body) ? (string) data_get($body, 'error.message', '') : $response->body();
        $tokenInvalid = $this->isInvalidToken($errorCode, $message);

        if (! $tokenInvalid) {
            Log::warning('fcm.send_failed', [
                'status' => $response->status(),
                'error_code' => $errorCode,
                'message' => $message,
            ]);
        }

        return new FcmSendResult(
            success: false,
            tokenInvalid: $tokenInvalid,
            error: $message !== '' ? $message : 'send_failed',
        );
    }

    private function isInvalidToken(string $errorCode, string $message): bool
    {
        $haystack = strtoupper($errorCode.' '.$message);

        return str_contains($haystack, 'UNREGISTERED')
            || str_contains($haystack, 'NOT_FOUND')
            || str_contains($haystack, 'INVALID_ARGUMENT');
    }
}
