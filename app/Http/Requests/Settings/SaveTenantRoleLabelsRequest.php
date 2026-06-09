<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use App\Support\TenantRoleLabels;
use Illuminate\Foundation\Http\FormRequest;

final class SaveTenantRoleLabelsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('administrator') === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = [];
        foreach (TenantRoleLabels::ROLE_KEYS as $role) {
            $rules[$role] = ['required', 'string', 'max:64'];
        }

        return $rules;
    }
}
