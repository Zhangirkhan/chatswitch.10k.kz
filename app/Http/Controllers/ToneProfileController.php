<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Jobs\AnalyzeCompanyToneProfileJob;
use App\Models\CompanyToneProfile;
use App\Models\EmployeeToneProfile;
use App\Support\TenantCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class ToneProfileController extends Controller
{
    public function index(): Response
    {
        $companyId = TenantCompany::id();

        $profile = CompanyToneProfile::query()->firstOrCreate(
            ['company_id' => $companyId],
            [
                'summary' => null,
                'phrases' => [],
                'metadata' => ['source' => 'empty'],
            ],
        );

        $employees = EmployeeToneProfile::query()
            ->where('company_id', $companyId)
            ->with(['user:id,name'])
            ->orderByDesc('analyzed_at')
            ->get()
            ->map(fn (EmployeeToneProfile $row): array => [
                'id' => $row->id,
                'user_name' => $row->user?->name ?? '—',
                'summary' => $row->summary,
                'phrases' => $row->phrases ?? [],
                'analyzed_at' => $row->analyzed_at?->toIso8601String(),
            ]);

        return Inertia::render('Settings/ToneProfile', [
            'profile' => [
                'id' => $profile->id,
                'summary' => $profile->summary,
                'phrases' => $profile->phrases ?? [],
                'use_manual_override' => (bool) $profile->use_manual_override,
                'manual_summary' => $profile->manual_summary,
                'manual_phrases' => $profile->manual_phrases ?? [],
                'analyzed_at' => $profile->analyzed_at?->toIso8601String(),
                'metadata' => $profile->metadata ?? [],
            ],
            'employee_profiles' => $employees,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $companyId = TenantCompany::id();

        $data = $request->validate([
            'use_manual_override' => ['required', 'boolean'],
            'manual_summary' => ['nullable', 'string', 'max:8000'],
            'manual_phrases' => ['nullable', 'array', 'max:20'],
            'manual_phrases.*' => ['string', 'max:300'],
        ]);

        $profile = CompanyToneProfile::query()->firstOrCreate(['company_id' => $companyId]);

        $phrases = collect($data['manual_phrases'] ?? [])
            ->filter(fn ($p): bool => is_string($p) && trim($p) !== '')
            ->map(fn (string $p): string => trim($p))
            ->values()
            ->all();

        $profile->update([
            'use_manual_override' => (bool) $data['use_manual_override'],
            'manual_summary' => $data['manual_summary'] ?? null,
            'manual_phrases' => $phrases,
        ]);

        return redirect()
            ->route('settings.tone-profile')
            ->with('success', 'Профиль тона сохранён.');
    }

    public function reanalyze(): RedirectResponse
    {
        $companyId = TenantCompany::id();
        AnalyzeCompanyToneProfileJob::dispatch($companyId);

        return redirect()
            ->route('settings.tone-profile')
            ->with('success', 'Пересборка профиля запущена. Обновите страницу через минуту.');
    }
}
