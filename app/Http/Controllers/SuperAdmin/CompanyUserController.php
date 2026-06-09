<?php

declare(strict_types=1);

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Department;
use App\Models\User;
use App\Services\SuperAdmin\SuperAdminAuditLogger;
use App\Support\TenantRoles;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

final class CompanyUserController extends Controller
{
    public function __construct(
        private readonly SuperAdminAuditLogger $audit,
    ) {}

    public function store(Request $request, Company $company): RedirectResponse
    {
        $this->normalizeNullableEmail($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => [
                'nullable',
                'email',
                'max:160',
                Rule::unique('users', 'email')->where('company_id', $company->id),
                Rule::requiredIf(fn (): bool => $request->input('role') === 'administrator'),
            ],
            'password' => ['required', 'string', 'min:8'],
            'role' => ['required', 'string', Rule::in(['administrator', 'manager', 'employee'])],
            'department_ids' => ['nullable', 'array'],
            'department_ids.*' => ['integer', Rule::exists('departments', 'id')->where('company_id', $company->id)],
        ]);

        $user = User::query()->withoutGlobalScope('tenant')->make([
            'name' => $data['name'],
            'email' => filled($data['email'] ?? null) ? $data['email'] : null,
            'password' => Hash::make($data['password']),
            'is_active' => true,
        ]);
        $user->forceFill(['company_id' => $company->id])->save();

        Role::findOrCreate($data['role'], 'web');
        TenantRoles::syncForCompany($user, $company->id, $data['role']);
        $user->syncDepartments($this->departmentIdsForCompany($data, $company));

        $this->audit->log($company, $request->user(), 'user.created', $user, [
            'email' => $user->email,
            'role' => $data['role'],
        ]);

        return back()->with('success', 'Пользователь создан.');
    }

    public function update(Request $request, Company $company, int $user): RedirectResponse
    {
        $user = User::query()
            ->withoutGlobalScope('tenant')
            ->where('company_id', $company->id)
            ->whereKey($user)
            ->firstOrFail();

        $this->normalizeNullableEmail($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => [
                'nullable',
                'email',
                'max:160',
                Rule::unique('users', 'email')->where('company_id', $company->id)->ignore($user->id),
                Rule::requiredIf(fn (): bool => $request->input('role') === 'administrator'),
            ],
            'is_active' => ['boolean'],
            'role' => ['required', 'string', Rule::in(['administrator', 'manager', 'employee'])],
            'password' => ['nullable', 'string', 'min:8'],
            'department_ids' => ['nullable', 'array'],
            'department_ids.*' => ['integer', Rule::exists('departments', 'id')->where('company_id', $company->id)],
        ]);

        $user->fill([
            'name' => $data['name'],
            'email' => filled($data['email'] ?? null) ? $data['email'] : null,
            'is_active' => (bool) ($data['is_active'] ?? true),
        ]);

        if (! empty($data['password'])) {
            $user->password = Hash::make($data['password']);
        }

        $user->save();

        TenantRoles::syncForCompany($user, $company->id, $data['role']);
        $user->syncDepartments($this->departmentIdsForCompany($data, $company));

        $this->audit->log($company, $request->user(), 'user.updated', $user, [
            'email' => $user->email,
            'is_active' => $user->is_active,
            'role' => $data['role'],
        ]);

        return back()->with('success', 'Пользователь обновлён.');
    }

    public function resetPassword(Request $request, Company $company, User $user): RedirectResponse
    {
        $user = User::query()->withoutGlobalScope('tenant')->where('company_id', $company->id)->whereKey($user->id)->firstOrFail();

        $data = $request->validate([
            'password' => ['nullable', 'string', 'min:8'],
        ]);

        $plain = $data['password'] ?? Str::password(12);
        $user->password = Hash::make($plain);
        $user->save();

        $this->audit->log($company, $request->user(), 'user.password_reset', $user, [
            'email' => $user->email,
        ]);

        return back()->with('success', 'Пароль сброшен для '.$user->name.': '.$plain);
    }

    private function normalizeNullableEmail(Request $request): void
    {
        $email = $request->input('email');
        if (is_string($email) && trim($email) === '') {
            $request->merge(['email' => null]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<int>
     */
    private function departmentIdsForCompany(array $data, Company $company): array
    {
        if (! isset($data['department_ids']) || ! is_array($data['department_ids'])) {
            return [];
        }

        $ids = array_values(array_unique(array_map(intval(...), $data['department_ids'])));

        if ($ids === []) {
            return [];
        }

        $valid = Department::query()
            ->withoutGlobalScope('tenant')
            ->where('company_id', $company->id)
            ->whereIn('id', $ids)
            ->pluck('id')
            ->all();

        return array_map(intval(...), $valid);
    }
}
