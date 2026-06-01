<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Contact\ContactFieldDefinitionService;
use App\Support\ContactFieldCatalog;
use App\Support\ContactFieldType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class ContactFieldDefinitionController extends Controller
{
    public function __construct(
        private readonly ContactFieldDefinitionService $definitions,
    ) {}

    public function index(Request $request): Response
    {
        abort_unless($request->user()?->hasRole('administrator'), 403);

        return Inertia::render('Settings/ContactFields', [
            'fields' => $this->definitions->listForCompany(),
            'field_types' => $this->typeOptions(),
            'groups' => ContactFieldCatalog::groupLabels(),
        ]);
    }

    public function list(Request $request): JsonResponse
    {
        abort_unless($request->user()?->hasRole('administrator'), 403);

        return response()->json([
            'fields' => $this->definitions->listForCompany(),
            'field_types' => $this->typeOptions(),
            'groups' => ContactFieldCatalog::groupLabels(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()?->hasRole('administrator'), 403);

        $data = $request->validate([
            'label' => ['required', 'string', 'max:120'],
            'type' => ['required', 'string'],
            'section' => ['nullable', 'string', 'max:32'],
            'group' => ['nullable', 'string', 'max:32'],
            'options' => ['nullable', 'array'],
            'options.choices' => ['nullable', 'array'],
            'options.choices.*' => ['string', 'max:120'],
        ]);

        $field = $this->definitions->createCustom([
            'label' => $data['label'],
            'type' => $data['type'],
            'section' => $data['section'] ?? 'contacts',
            'group' => $data['group'] ?? 'additional',
            'options' => $data['options'] ?? null,
        ]);

        return response()->json(['field' => $field], 201);
    }

    public function syncVisibility(Request $request): JsonResponse
    {
        abort_unless($request->user()?->hasRole('administrator'), 403);

        $data = $request->validate([
            'visibility' => ['required', 'array'],
            'visibility.*.id' => ['required', 'integer'],
            'visibility.*.is_visible' => ['required', 'boolean'],
        ]);

        $this->definitions->syncVisibility($data['visibility']);

        return response()->json([
            'fields' => $this->definitions->listForCompany(),
        ]);
    }

    public function destroy(Request $request, int $fieldDefinition): JsonResponse
    {
        abort_unless($request->user()?->hasRole('administrator'), 403);

        $this->definitions->deleteCustom($fieldDefinition);

        return response()->json([
            'fields' => $this->definitions->listForCompany(),
        ]);
    }

    /**
     * @return list<array{id: string, label: string, description: string|null}>
     */
    private function typeOptions(): array
    {
        $options = [];
        foreach (ContactFieldType::values() as $type) {
            $options[] = [
                'id' => $type,
                'label' => ContactFieldType::labels()[$type] ?? $type,
                'description' => ContactFieldType::descriptions()[$type] ?? null,
            ];
        }

        return $options;
    }
}
