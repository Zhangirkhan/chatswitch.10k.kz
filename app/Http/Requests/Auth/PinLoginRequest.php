<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Models\User;
use App\Services\Auth\UserPinRateLimiter;
use App\Services\Auth\UserPinService;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
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
            'pin' => ['required', 'string', 'regex:/^\d{6}$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'pin.regex' => 'PIN должен состоять из 6 цифр.',
        ];
    }

    /**
     * @throws ValidationException
     */
    public function authenticate(): User
    {
        $context = app(TenantContext::class);
        if (! $context->isResolved()) {
            $context->resolveBySlug((string) config('tenancy.fallback_slug', 'demo'));
        }

        $companyId = $context->companyId();
        $rateLimiter = app(UserPinRateLimiter::class);
        $rateLimiter->ensureNotRateLimited($this, $companyId);

        $pin = $this->string('pin')->toString();
        $user = app(UserPinService::class)->findActiveUserByPin($companyId, $pin);

        if ($user === null) {
            $rateLimiter->hit($this, $companyId);

            throw ValidationException::withMessages([
                'pin' => 'Неверный PIN или вход по PIN не настроен.',
            ]);
        }

        Auth::login($user, $this->boolean('remember'));
        $rateLimiter->clear($this, $companyId);

        return $user;
    }
}
