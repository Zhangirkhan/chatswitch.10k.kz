<?php

declare(strict_types=1);

namespace App\Services\Auth;

use Illuminate\Auth\Events\Lockout;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class UserPinRateLimiter
{
    public const IP_MAX_ATTEMPTS = 8;

    public const COMPANY_MAX_ATTEMPTS = 25;

    public const DECAY_SECONDS = 900;

    /**
     * @throws ValidationException
     */
    public function ensureNotRateLimited(Request $request, int $companyId): void
    {
        $ipKey = $this->ipThrottleKey($request, $companyId);
        $companyKey = $this->companyThrottleKey($companyId);

        if (! RateLimiter::tooManyAttempts($ipKey, self::IP_MAX_ATTEMPTS)
            && ! RateLimiter::tooManyAttempts($companyKey, self::COMPANY_MAX_ATTEMPTS)) {
            return;
        }

        event(new Lockout($request));

        $seconds = max(
            RateLimiter::availableIn($ipKey),
            RateLimiter::availableIn($companyKey),
        );

        throw ValidationException::withMessages([
            'pin' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => (int) ceil($seconds / 60),
            ]),
        ]);
    }

    public function hit(Request $request, int $companyId): void
    {
        $ipKey = $this->ipThrottleKey($request, $companyId);
        $companyKey = $this->companyThrottleKey($companyId);

        RateLimiter::hit($ipKey, self::DECAY_SECONDS);
        RateLimiter::hit($companyKey, self::DECAY_SECONDS);
    }

    public function clear(Request $request, int $companyId): void
    {
        RateLimiter::clear($this->ipThrottleKey($request, $companyId));
        RateLimiter::clear($this->companyThrottleKey($companyId));
    }

    private function ipThrottleKey(Request $request, int $companyId): string
    {
        return Str::transliterate('pin|'.$companyId.'|'.$request->ip());
    }

    private function companyThrottleKey(int $companyId): string
    {
        return Str::transliterate('pin-company|'.$companyId);
    }
}
