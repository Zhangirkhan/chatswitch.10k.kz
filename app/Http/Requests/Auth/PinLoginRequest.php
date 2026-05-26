<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Models\User;
use App\Services\Auth\UserPinService;
use App\Tenancy\TenantContext;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class PinLoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        $context = app(TenantContext::class);

        return ! $context->isAdminHost((string) $this->getHost());
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'pin' => ['required', 'string', 'regex:/^\d{4,6}$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'pin.regex' => 'PIN должен состоять из 4–6 цифр.',
        ];
    }

    /**
     * @throws ValidationException
     */
    public function authenticate(): User
    {
        $this->ensureIsNotRateLimited();

        $context = app(TenantContext::class);
        if (! $context->isResolved()) {
            $context->resolveBySlug((string) config('tenancy.fallback_slug', 'demo'));
        }

        $companyId = $context->companyId();
        if ($companyId === null) {
            throw ValidationException::withMessages([
                'pin' => trans('auth.failed'),
            ]);
        }

        $pin = $this->string('pin')->toString();
        $user = app(UserPinService::class)->findActiveUserByPin($companyId, $pin);

        if ($user === null) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'pin' => 'Неверный PIN или вход по PIN не настроен.',
            ]);
        }

        Auth::login($user, $this->boolean('remember'));
        RateLimiter::clear($this->throttleKey());

        return $user;
    }

    /**
     * @throws ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 8)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'pin' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => (int) ceil($seconds / 60),
            ]),
        ]);
    }

    public function throttleKey(): string
    {
        $context = app(TenantContext::class);
        $companyId = $context->companyId() ?? 'unknown';

        return Str::transliterate('pin|'.$companyId.'|'.$this->ip());
    }
}
