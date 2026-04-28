<?php

declare(strict_types=1);

namespace App\Http\Requests\Chat;

use Illuminate\Foundation\Http\FormRequest;

final class CreateGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'subject' => ['required', 'string', 'max:100'],
            'contact_ids' => ['required', 'array', 'min:1'],
            'contact_ids.*' => ['integer', 'exists:contacts,id'],
            'whatsapp_session_id' => ['required', 'integer', 'exists:whatsapp_sessions,id'],
            'community_id' => ['nullable', 'integer', 'exists:communities,id'],
        ];
    }
}
