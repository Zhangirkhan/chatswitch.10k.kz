<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\TenantRoleService;
use App\Support\TenantCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

final class TenantRoleController extends Controller
{
    public function __construct(
        private readonly TenantRoleService $tenantRoleService,
    ) {}

    public function index(): Response
    {
        $companyId = TenantCompany::id();
        $catalog = $this->tenantRoleService->permissionCatalog();

        return Inertia::render('Settings/Roles', [
            'roles' => $this->tenantRoleService->listForCompany($companyId)->values()->all(),
            'permissionGroups' => $catalog['groups'],
            'protectedRoleNames' => $catalog['protected_role_names'],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $companyId = TenantCompany::id();
        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:64',
                Rule::unique('roles', 'name')
                    ->where(fn ($q) => $q
                        ->where('guard_name', 'web')
                        ->where(config('permission.column_names.team_foreign_key'), $companyId)),
            ],
            'permissions' => ['required', 'array', 'min:1'],
            'permissions.*' => ['string'],
        ]);

        $this->tenantRoleService->create(
            $companyId,
            $validated['name'],
            $validated['permissions'],
        );

        return redirect()
            ->route('settings.roles')
            ->with('success', 'Роль создана.');
    }

    public function update(Request $request, Role $role): RedirectResponse
    {
        $companyId = TenantCompany::id();
        $this->assertRoleInTenant($role, $companyId);

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:64',
                Rule::unique('roles', 'name')
                    ->where(fn ($q) => $q
                        ->where('guard_name', 'web')
                        ->where(config('permission.column_names.team_foreign_key'), $companyId))
                    ->ignore($role->id),
            ],
            'permissions' => ['required', 'array', 'min:1'],
            'permissions.*' => ['string'],
        ]);

        $this->tenantRoleService->update($role, $validated['name'], $validated['permissions']);

        return redirect()
            ->route('settings.roles')
            ->with('success', 'Роль обновлена.');
    }

    public function destroy(Role $role): RedirectResponse
    {
        $this->assertRoleInTenant($role, TenantCompany::id());
        $this->tenantRoleService->delete($role);

        return redirect()
            ->route('settings.roles')
            ->with('success', 'Роль удалена.');
    }

    private function assertRoleInTenant(Role $role, int $companyId): void
    {
        abort_unless(
            (int) $role->{config('permission.column_names.team_foreign_key')} === $companyId,
            404,
        );
    }
}
