<?php

declare(strict_types=1);

namespace App\Services\Security;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final class RecaptchaVerifier
{
    public static function isEnabled(): bool
    {
        return (bool) config('recaptcha.enabled', false)
            && filled(config('recaptcha.site_key'))
            && filled(config('recaptcha.secret_key'));
    }

    public function verify(string $token, ?string $remoteIp, ?string $action = null): bool
    {
        if (! self::isEnabled()) {
            return true;
        }

        $secret = (string) config('recaptcha.secret_key');
        if ($secret === '' || $token === '') {
            return false;
        }

        $response = Http::asForm()
            ->timeout(10)
            ->post((string) config('recaptcha.verify_url'), [
                'secret' => $secret,
                'response' => $token,
                'remoteip' => $remoteIp,
            ]);

        if (! $response->successful()) {
            Log::warning('recaptcha verify HTTP failed', ['status' => $response->status()]);

            return false;
        }

        /** @var array{success?: bool, score?: float, action?: string, 'error-codes'?: list<string>} $body */
        $body = $response->json();
        if (! ($body['success'] ?? false)) {
            Log::info('recaptcha rejected', ['errors' => $body['error-codes'] ?? []]);

            return false;
        }

        if (config('recaptcha.version') === 'v3') {
            $score = (float) ($body['score'] ?? 0);
            if ($score < (float) config('recaptcha.min_score', 0.5)) {
                return false;
            }

            if ($action !== null && $action !== '' && isset($body['action']) && $body['action'] !== $action) {
                return false;
            }
        }

        return true;
    }
}
