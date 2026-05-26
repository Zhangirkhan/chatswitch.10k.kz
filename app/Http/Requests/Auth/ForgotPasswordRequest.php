<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Rules\Recaptcha;
use App\Services\Security\RecaptchaVerifier;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ForgotPasswordRequest extends FormRequest
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
            'email' => ['required', 'email'],
            'recaptcha_token' => [
                Rule::excludeIf(fn (): bool => ! RecaptchaVerifier::isEnabled()),
                Rule::requiredIf(fn (): bool => RecaptchaVerifier::isEnabled()),
                'string',
                new Recaptcha('forgot_password'),
            ],
        ];
    }
}
