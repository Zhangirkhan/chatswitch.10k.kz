<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class DialogAnalyticsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        if ($user === null) {
            return false;
        }

        if (! $user->hasAnyRole(['administrator', 'manager', 'employee'])) {
            return false;
        }

        $employeeId = $this->filled('employee_id') ? (int) $this->input('employee_id') : null;
        if ($employeeId !== null) {
            if ($user->hasRole('employee') && (int) $employeeId !== (int) $user->id) {
                return false;
            }
            if ($user->hasRole('manager')) {
                $managerDeptIds = $user->departmentIds();
                if ($managerDeptIds === []) {
                    return false;
                }
                $ok = User::query()
                    ->where('id', $employeeId)
                    ->whereHas('departments', static fn ($q) => $q->whereIn('departments.id', $managerDeptIds))
                    ->exists();
                if (! $ok) {
                    return false;
                }
            }
        }

        $departmentId = $this->filled('department_id') ? (int) $this->input('department_id') : null;
        if ($departmentId !== null && $user->hasRole('manager')) {
            if (! in_array((int) $departmentId, $user->departmentIds(), true)) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            'employee_id' => ['nullable', 'integer', 'exists:users,id'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'status' => ['nullable', Rule::in(['all', 'active', 'closed', 'waiting'])],
            'channel' => ['nullable', Rule::in(['all', 'whatsapp', 'telegram', 'site'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:50'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'status' => $this->input('status', 'all'),
            'channel' => $this->input('channel', 'all'),
            'page' => $this->input('page', 1),
            'per_page' => $this->input('per_page', 15),
        ]);
    }
}
