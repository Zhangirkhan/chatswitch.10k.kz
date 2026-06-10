<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\UserFeedbackType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreUserFeedbackRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'type' => ['required', 'string', Rule::enum(UserFeedbackType::class)],
            'message' => ['required', 'string', 'min:10', 'max:5000'],
        ];
    }
}
