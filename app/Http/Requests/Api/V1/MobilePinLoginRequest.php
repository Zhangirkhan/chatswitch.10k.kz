<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Models\User;
use App\Services\Auth\UserPinService;
use App\Tenancy\TenantContext;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class MobilePinLoginRequest extends FormRequest
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
        $companyId = $context->companyIdOrNull() ?? 'unknown';

        return Str::transliterate('pin|'.$companyId.'|'.$this->ip());
    }

    /**
     * @throws ValidationException
     */
    public function authenticateUser(): User
    {
        $this->ensureIsNotRateLimited();

        $context = app(TenantContext::class);
        if (! $context->isResolved()) {
            throw ValidationException::withMessages([
                'pin' => 'Неверный PIN или вход по PIN не настроен.',
            ]);
        }

        $companyId = $context->companyId();
        $pin = $this->string('pin')->toString();
        $user = app(UserPinService::class)->findUserByPin($companyId, $pin);

        if ($user === null) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'pin' => 'Неверный PIN или вход по PIN не настроен.',
            ]);
        }

        if (! $user->is_active) {
            throw new HttpResponseException(response()->json([
                'message' => 'Ваш аккаунт деактивирован.',
            ], 403));
        }

        RateLimiter::clear($this->throttleKey());

        return $user;
    }
}
