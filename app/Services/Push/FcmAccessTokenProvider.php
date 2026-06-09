<?php

declare(strict_types=1);

namespace App\Services\Push;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final class FcmAccessTokenProvider
{
    public function getAccessToken(): ?string
    {
        if (! $this->isConfigured()) {
            return null;
        }

        $credentialsPath = (string) config('services.firebase.credentials');

        return Cache::remember('fcm_access_token', 3300, function () use ($credentialsPath): ?string {
            $json = json_decode((string) file_get_contents($credentialsPath), true);
            if (! is_array($json)) {
                return null;
            }

            $clientEmail = (string) ($json['client_email'] ?? '');
            $privateKey = (string) ($json['private_key'] ?? '');
            if ($clientEmail === '' || $privateKey === '') {
                return null;
            }

            $jwt = $this->buildJwt($clientEmail, $privateKey);
            if ($jwt === null) {
                return null;
            }

            $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ]);

            if (! $response->successful()) {
                Log::warning('fcm.oauth_failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return null;
            }

            $token = $response->json('access_token');

            return is_string($token) && $token !== '' ? $token : null;
        });
    }

    public function projectId(): ?string
    {
        $credentialsPath = (string) config('services.firebase.credentials');
        if ($credentialsPath === '' || ! is_file($credentialsPath)) {
            return null;
        }

        $json = json_decode((string) file_get_contents($credentialsPath), true);
        if (! is_array($json)) {
            return null;
        }

        $projectId = (string) ($json['project_id'] ?? config('services.firebase.project_id', ''));

        return $projectId !== '' ? $projectId : null;
    }

    public function isConfigured(): bool
    {
        $credentialsPath = (string) config('services.firebase.credentials');

        return config('services.firebase.enabled', false)
            && $credentialsPath !== ''
            && is_file($credentialsPath);
    }

    private function buildJwt(string $clientEmail, string $privateKey): ?string
    {
        $now = time();
        $header = $this->base64UrlEncode(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
        $payload = $this->base64UrlEncode(json_encode([
            'iss' => $clientEmail,
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'aud' => 'https://oauth2.googleapis.com/token',
            'iat' => $now,
            'exp' => $now + 3600,
        ], JSON_THROW_ON_ERROR));

        $unsigned = $header.'.'.$payload;
        $signature = '';
        $ok = openssl_sign($unsigned, $signature, $privateKey, OPENSSL_ALGO_SHA256);
        if (! $ok) {
            Log::warning('fcm.jwt_sign_failed');

            return null;
        }

        return $unsigned.'.'.$this->base64UrlEncode($signature);
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
