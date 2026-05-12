<?php

declare(strict_types=1);

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;

final class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('administrator') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:40'],
            'phones' => ['nullable', 'array'],
            'phones.*' => ['nullable', 'string', 'max:40'],
            'password' => ['required', 'string', 'min:6'],
            'role' => ['required', 'string', 'in:administrator,manager,employee'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'department_ids' => ['nullable', 'array'],
            'department_ids.*' => ['integer', 'exists:departments,id'],
            'whatsapp_session_ids' => ['nullable', 'array'],
            'whatsapp_session_ids.*' => ['integer', 'exists:whatsapp_sessions,id'],
        ];
    }
}
