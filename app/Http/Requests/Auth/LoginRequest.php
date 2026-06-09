<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Models\User;
use App\Rules\Recaptcha;
use App\Services\Auth\TenantLoginService;
use App\Services\Security\RecaptchaVerifier;
use App\Tenancy\TenantContext;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (! $this->has('login') && $this->has('email')) {
            $this->merge([
                'login' => $this->input('email'),
            ]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $context = app(TenantContext::class);
        $isSuperAdminHost = $context->isAdminHost((string) $this->getHost());

        return [
            'login' => [
                'required',
                'string',
                $isSuperAdminHost ? 'email' : 'min:3',
            ],
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
        $login = $this->string('login')->toString();
        $password = $this->string('password')->toString();

        if ($context->isAdminHost($host)) {
            $credentials = [
                'email' => $login,
                'password' => $password,
                'is_super_admin' => true,
            ];

            if (! Auth::attempt($credentials, $this->boolean('remember'))) {
                $this->failAuthentication();
            }

            RateLimiter::clear($this->throttleKey());

            return;
        }

        if (! $context->isResolved()) {
            $context->resolveBySlug((string) config('tenancy.fallback_slug', 'demo'));
        }

        $loginService = app(TenantLoginService::class);
        $user = $loginService->findUserByLogin($context->companyId(), $login);

        if (
            ! $user instanceof User
            || ! $loginService->verifyPassword($user, $password)
        ) {
            $this->failAuthentication();
        }

        Auth::login($user, $this->boolean('remember'));
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
            'login' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => (int) ceil($seconds / 60),
            ]),
        ]);
    }

    public function throttleKey(): string
    {
        return app(TenantLoginService::class)->throttleKey(
            $this->string('login')->toString(),
            (string) $this->ip(),
        );
    }

    /**
     * @throws ValidationException
     */
    private function failAuthentication(): never
    {
        RateLimiter::hit($this->throttleKey());

        throw ValidationException::withMessages([
            'login' => trans('auth.failed'),
        ]);
    }
}
