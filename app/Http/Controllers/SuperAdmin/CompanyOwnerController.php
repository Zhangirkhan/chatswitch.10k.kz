<?php

declare(strict_types=1);

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\User;
use App\Services\SuperAdmin\CompanyOwnerService;
use App\Services\SuperAdmin\SuperAdminCompanyScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class CompanyOwnerController extends Controller
{
    public function __construct(
        private readonly CompanyOwnerService $owners,
        private readonly SuperAdminCompanyScope $superAdminScope,
    ) {}

    public function update(Request $request, Company $company): RedirectResponse
    {
        $this->superAdminScope->ensureCanManage($request->user(), $company);

        $data = $request->validate([
            'user_id' => [
                'required',
                'integer',
                Rule::exists('users', 'id')->where('company_id', $company->id),
            ],
        ]);

        $user = User::query()
            ->withoutGlobalScope('tenant')
            ->where('company_id', $company->id)
            ->whereKey((int) $data['user_id'])
            ->firstOrFail();

        $this->owners->assign($company, $user, $request->user());

        return back()->with('success', 'Владелец тенанта назначен: '.($user->email ?? $user->name));
    }
}
