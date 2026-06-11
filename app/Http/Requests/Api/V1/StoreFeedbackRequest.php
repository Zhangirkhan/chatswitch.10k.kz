<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Enums\UserFeedbackType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreFeedbackRequest extends FormRequest
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
            'app_version' => ['nullable', 'string', 'max:64'],
            'device_platform' => ['nullable', 'string', 'max:32'],
            'device_model' => ['nullable', 'string', 'max:128'],
            'device_manufacturer' => ['nullable', 'string', 'max:64'],
            'os_version' => ['nullable', 'string', 'max:128'],
            'locale' => ['nullable', 'string', 'max:16'],
        ];
    }
}
