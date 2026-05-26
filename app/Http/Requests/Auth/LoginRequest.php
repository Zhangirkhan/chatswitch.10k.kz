<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Rules\Recaptcha;
use App\Services\Security\RecaptchaVerifier;
use App\Tenancy\TenantContext;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
            'recaptcha_token' => [
                Rule::excludeIf(fn (): bool => ! RecaptchaVerifier::isEnabled()),
                Rule::requiredIf(fn (): bool => RecaptchaVerifier::isEnabled()),
                'string',
                new Recaptcha('login'),
            ],
        ];
    }

    /**
     * @throws ValidationException
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        $context = app(TenantContext::class);
        $host = (string) $this->getHost();

        if ($context->isAdminHost($host)) {
            $credentials = [
                'email' => $this->string('email')->toString(),
                'password' => $this->string('password')->toString(),
                'is_super_admin' => true,
            ];
        } else {
            if (! $context->isResolved()) {
                $context->resolveBySlug((string) config('tenancy.fallback_slug', 'demo'));
            }

            $credentials = [
                'email' => $this->string('email')->toString(),
                'password' => $this->string('password')->toString(),
                'company_id' => $context->companyId(),
            ];
        }

        if (! Auth::attempt($credentials, $this->boolean('remember'))) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'email' => trans('auth.failed'),
            ]);
        }

        RateLimiter::clear($this->throttleKey());
    }

    /**
     * @throws ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => (int) ceil($seconds / 60),
            ]),
        ]);
    }

    public function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->string('email')).'|'.$this->ip());
    }
}
