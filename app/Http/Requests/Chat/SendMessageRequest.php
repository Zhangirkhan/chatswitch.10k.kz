<?php

declare(strict_types=1);

namespace App\Http\Requests\Chat;

use App\Models\Chat;
use Illuminate\Foundation\Http\FormRequest;

final class SendMessageRequest extends FormRequest
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
            'message' => ['required', 'string', 'max:4096'],
            'quoted_message_id' => ['nullable', 'string', 'max:255'],
        ];
    }
}
