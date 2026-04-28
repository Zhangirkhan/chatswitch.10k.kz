<?php

declare(strict_types=1);

namespace App\Http\Requests\Chat;

use App\Models\Chat;
use Illuminate\Foundation\Http\FormRequest;

final class SendContactRequest extends FormRequest
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
            'name' => ['nullable', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:64'],
            'email' => ['nullable', 'string', 'max:255'],
            'company' => ['nullable', 'string', 'max:255'],
            'avatar_url' => ['nullable', 'string', 'max:2048'],
            'contact_id' => ['nullable', 'integer', 'exists:contacts,id'],
        ];
    }
}
