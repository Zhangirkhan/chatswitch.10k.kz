<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Contact;
use App\Models\ContactStakeholder;
use App\Services\Contact\StakeholderDetectionService;
use App\Support\TenantCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ContactStakeholderController extends Controller
{
    public function index(Contact $contact): JsonResponse
    {
        $this->authorizeContact($contact);

        $rows = app(StakeholderDetectionService::class)
            ->forAccountContact($contact->id)
            ->map(static fn (ContactStakeholder $row): array => [
                'id' => $row->id,
                'role' => $row->role,
                'influence' => $row->influence,
                'notes' => $row->notes,
                'source' => $row->source,
                'name' => $row->stakeholderContact?->name,
                'contact_id' => $row->stakeholder_contact_id,
            ]);

        return response()->json(['stakeholders' => $rows]);
    }

    public function store(Request $request, Contact $contact): JsonResponse
    {
        $this->authorizeContact($contact);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'role' => ['required', 'string', 'in:decision_maker,influencer,blocker,finance,user'],
            'influence' => ['nullable', 'integer', 'min:0', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $row = app(StakeholderDetectionService::class)->upsertStakeholder(
            TenantCompany::id(),
            $contact->id,
            $validated['name'],
            $validated['role'],
            $validated['notes'] ?? null,
            ContactStakeholder::SOURCE_MANAGER,
        );

        if (isset($validated['influence'])) {
            $row->forceFill(['influence' => (int) $validated['influence']])->save();
        }

        return response()->json(['success' => true, 'stakeholder' => $row->fresh('stakeholderContact')]);
    }

    public function destroy(Contact $contact, ContactStakeholder $stakeholder): JsonResponse
    {
        $this->authorizeContact($contact);
        abort_unless((int) $stakeholder->account_contact_id === (int) $contact->id, 404);

        $stakeholder->delete();

        return response()->json(['success' => true]);
    }

    private function authorizeContact(Contact $contact): void
    {
        abort_unless((int) $contact->company_id === TenantCompany::id(), 404);
    }
}
