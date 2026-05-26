<?php

declare(strict_types=1);

namespace App\Rules;

use App\Services\Security\RecaptchaVerifier;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final class Recaptcha implements ValidationRule
{
    public function __construct(
        private readonly ?string $action = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! RecaptchaVerifier::isEnabled()) {
            return;
        }

        if (! is_string($value) || trim($value) === '') {
            $fail('Подтвердите, что вы не робот.');

            return;
        }

        if (! app(RecaptchaVerifier::class)->verify($value, request()->ip(), $this->action)) {
            $fail('Проверка reCAPTCHA не пройдена. Обновите страницу и попробуйте снова.');
        }
    }
}
