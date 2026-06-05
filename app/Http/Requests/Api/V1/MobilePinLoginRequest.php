<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Models\User;
use App\Services\Auth\UserPinRateLimiter;
use App\Services\Auth\UserPinService;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
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
    public function authenticateUser(): User
    {
        $context = app(TenantContext::class);
        if (! $context->isResolved()) {
            throw ValidationException::withMessages([
                'pin' => 'Неверный PIN или вход по PIN не настроен.',
            ]);
        }

        $companyId = $context->companyId();
        $rateLimiter = app(UserPinRateLimiter::class);
        $rateLimiter->ensureNotRateLimited($this, $companyId);

        $pin = $this->string('pin')->toString();
        $user = app(UserPinService::class)->findUserByPin($companyId, $pin);

        if ($user === null) {
            $rateLimiter->hit($this, $companyId);

            throw ValidationException::withMessages([
                'pin' => 'Неверный PIN или вход по PIN не настроен.',
            ]);
        }

        if (! $user->is_active) {
            throw new HttpResponseException(response()->json([
                'message' => 'Ваш аккаунт деактивирован.',
            ], 403));
        }

        $rateLimiter->clear($this, $companyId);

        return $user;
    }
}
