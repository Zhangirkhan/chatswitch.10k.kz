<?php

declare(strict_types=1);

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\SuperAdmin\CompanyModuleSettingsService;
use App\Support\CompanyModules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class CompanyModuleController extends Controller
{
    public function __construct(
        private readonly CompanyModuleSettingsService $modules,
    ) {}

    public function update(Request $request, Company $company): RedirectResponse
    {
        $validated = $request->validate([
            'modules' => ['required', 'array'],
            'modules.*' => ['required', 'boolean'],
        ]);

        $allowed = array_fill_keys(CompanyModules::keys(), true);
        $payload = array_intersect_key($validated['modules'], $allowed);

        $this->modules->update($company, $payload, $request->user());

        return back()->with('success', 'Модули компании обновлены.');
    }
}
