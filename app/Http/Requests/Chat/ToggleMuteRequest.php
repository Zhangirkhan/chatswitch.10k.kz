<?php

declare(strict_types=1);

namespace App\Http\Requests\Chat;

use App\Models\Chat;
use Illuminate\Foundation\Http\FormRequest;

final class ToggleMuteRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Chat|null $chat */
        $chat = $this->route('chat');

        return $chat !== null && $this->user()?->can('manage', $chat) === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'duration' => ['nullable', 'string', 'in:8h,1w,always'],
            'unmute' => ['nullable', 'boolean'],
        ];
    }
}
