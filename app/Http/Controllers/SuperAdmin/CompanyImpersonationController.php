<?php

declare(strict_types=1);

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\SuperAdmin\SuperAdminCompanyScope;
use App\Services\SuperAdmin\TenantImpersonationService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

final class CompanyImpersonationController extends Controller
{
    public function __construct(
        private readonly SuperAdminCompanyScope $superAdminScope,
    ) {}

    public function store(Request $request, Company $company, TenantImpersonationService $impersonation): Response
    {
        $superAdmin = $request->user();

        if ($superAdmin === null || ! $superAdmin->is_super_admin) {
            abort(403);
        }

        $this->superAdminScope->ensureCanManage($superAdmin, $company);

        $url = $impersonation->issueRedirectUrl($company, $superAdmin);

        if ($request->header('X-Inertia')) {
            return Inertia::location($url);
        }

        return redirect()->away($url);
    }
}
