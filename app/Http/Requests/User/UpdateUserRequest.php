<?php

declare(strict_types=1);

namespace App\Http\Requests\User;

use App\Models\User;
use App\Support\TenantCompany;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('administrator') === true;
    }

    protected function prepareForValidation(): void
    {
        $email = $this->input('email');
        if (is_string($email) && trim($email) === '') {
            $this->merge(['email' => null]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        /** @var User $user */
        $user = $this->route('user');

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'nullable',
                'email',
                'max:255',
                Rule::unique('users', 'email')
                    ->where('company_id', TenantCompany::id())
                    ->ignore($user->id),
                Rule::requiredIf(fn (): bool => $this->input('role') === 'administrator'),
            ],
            'phone' => ['nullable', 'string', 'max:40'],
            'phones' => ['nullable', 'array'],
            'phones.*' => ['nullable', 'string', 'max:40'],
            'password' => ['nullable', 'string', 'min:6'],
            'role' => [
                'required',
                'string',
                Rule::exists('roles', 'name')
                    ->where(fn ($query) => $query
                        ->where('guard_name', 'web')
                        ->where(config('permission.column_names.team_foreign_key'), TenantCompany::id())),
            ],
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'department_ids' => ['nullable', 'array'],
            'department_ids.*' => ['integer', 'exists:departments,id'],
            'is_active' => ['boolean'],
            'whatsapp_session_ids' => ['nullable', 'array'],
            'whatsapp_session_ids.*' => ['integer', 'exists:whatsapp_sessions,id'],
            'pin' => ['nullable', 'string', 'regex:/^\d{6}$/'],
        ];
    }
}
