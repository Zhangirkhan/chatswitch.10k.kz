<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Company;
use App\Services\Company\CompanyOnboardingService;
use App\Support\TenantCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CompanyController extends Controller
{
    public function store(Request $request, CompanyOnboardingService $onboarding): JsonResponse
    {
        $company = TenantCompany::ensureExists();
        $company->update($this->validatedPayload($request));
        $onboarding->bootstrap($company);

        return response()->json([
            'success' => true,
            'company' => $this->transformCompany($company),
        ]);
    }

    public function update(Request $request, Company $company): JsonResponse
    {
        abort_unless((int) $company->id === TenantCompany::id(), 404);

        $company->update($this->validatedPayload($request));

        return response()->json([
            'success' => true,
            'company' => $this->transformCompany($company->fresh()),
        ]);
    }

    public function destroy(Company $company): JsonResponse
    {
        abort_unless((int) $company->id === TenantCompany::id(), 404);
        abort(403, 'Текущую компанию нельзя удалить.');

        $company->delete();

        return response()->json(['success' => true]);
    }

    /**
     * @return array{name: string, phone: ?string, email: ?string, website: ?string, description: ?string}
     */
    private function validatedPayload(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:160'],
            'website' => ['nullable', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);

        return [
            'name' => trim((string) $data['name']),
            'phone' => $this->nullableString($data['phone'] ?? null),
            'email' => $this->nullableString($data['email'] ?? null),
            'website' => $this->nullableString($data['website'] ?? null),
            'description' => $this->nullableString($data['description'] ?? null),
        ];
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value !== '' ? $value : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function transformCompany(?Company $company): array
    {
        if ($company === null) {
            return [];
        }

        $company->loadMissing('contacts:id,name,push_name,phone_number,profile_picture_url');

        return [
            'id' => $company->id,
            'name' => $company->name,
            'phone' => $company->phone,
            'email' => $company->email,
            'website' => $company->website,
            'description' => $company->description,
            'clients_count' => $company->contacts->count(),
            'clients' => $company->contacts
                ->map(fn ($contact) => [
                    'id' => $contact->id,
                    'name' => $contact->name ?: $contact->push_name ?: $contact->phone_number ?: 'Без имени',
                    'phone_number' => $contact->phone_number,
                    'position' => $contact->pivot?->position,
                ])
                ->values()
                ->all(),
        ];
    }
}
