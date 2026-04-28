<?php

declare(strict_types=1);

namespace App\Http\Requests\Chat;

use App\Models\Chat;
use Illuminate\Foundation\Http\FormRequest;

final class SendPollRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Chat|null $chat */
        $chat = $this->route('chat');

        return $chat !== null && $this->user()?->can('sendMessage', $chat) === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'question' => ['required', 'string', 'max:255'],
            'options' => ['required', 'array', 'min:2', 'max:12'],
            'options.*' => ['required', 'string', 'max:100'],
            'allow_multiple_answers' => ['nullable', 'boolean'],
        ];
    }
}
