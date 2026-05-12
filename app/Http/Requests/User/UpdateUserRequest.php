<?php

declare(strict_types=1);

namespace App\Http\Requests\User;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('administrator') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        /** @var User $user */
        $user = $this->route('user');

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', Rule::unique('users', 'email')->ignore($user->id)],
            'phone' => ['nullable', 'string', 'max:40'],
            'phones' => ['nullable', 'array'],
            'phones.*' => ['nullable', 'string', 'max:40'],
            'password' => ['nullable', 'string', 'min:6'],
            'role' => ['required', 'string', 'in:administrator,manager,employee'],
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'department_ids' => ['nullable', 'array'],
            'department_ids.*' => ['integer', 'exists:departments,id'],
            'is_active' => ['boolean'],
            'whatsapp_session_ids' => ['nullable', 'array'],
            'whatsapp_session_ids.*' => ['integer', 'exists:whatsapp_sessions,id'],
        ];
    }
}
