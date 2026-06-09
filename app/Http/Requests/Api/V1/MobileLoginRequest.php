<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Models\User;
use App\Services\Auth\TenantLoginService;
use App\Tenancy\TenantContext;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

final class MobileLoginRequest extends FormRequest
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
        return [
            'login' => ['required', 'string', 'min:3'],
            'password' => ['required', 'string'],
        ];
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
            'login' => [trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ])],
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
     * Аутентификация для API: активный пользователь или 403 JSON.
     *
     * @throws ValidationException
     */
    public function authenticateUser(): User
    {
        $this->ensureIsNotRateLimited();

        $context = app(TenantContext::class);
        if (! $context->isResolved()) {
            throw ValidationException::withMessages([
                'login' => [trans('auth.failed')],
            ]);
        }

        $loginService = app(TenantLoginService::class);
        $login = $this->string('login')->toString();
        $password = $this->string('password')->toString();
        $user = $loginService->findUserByLogin($context->companyId(), $login);

        if (
            ! $user instanceof User
            || ! $loginService->verifyPassword($user, $password)
        ) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'login' => [trans('auth.failed')],
            ]);
        }

        Auth::login($user);
        RateLimiter::clear($this->throttleKey());

        if (! $user->is_active) {
            Auth::logout();

            throw new HttpResponseException(response()->json([
                'message' => 'Ваш аккаунт деактивирован.',
            ], 403));
        }

        return $user;
    }
}
