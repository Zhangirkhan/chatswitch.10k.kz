<?php

declare(strict_types=1);

namespace App\Services\Contact;

use App\Models\ContactFieldDefinition;
use App\Support\ContactFieldCatalog;
use App\Support\ContactFieldType;
use App\Support\TenantCompany;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ContactFieldDefinitionService
{
    /**
     * @return list<array<string, mixed>>
     */
    public function listForCompany(?int $companyId = null): array
    {
        $companyId ??= TenantCompany::id();
        $this->ensureSystemFields($companyId);

        return ContactFieldDefinition::query()
            ->where('company_id', $companyId)
            ->orderBy('group')
            ->orderBy('sort_order')
            ->orderBy('label')
            ->get()
            ->map(fn (ContactFieldDefinition $row): array => $this->serialize($row))
            ->values()
            ->all();
    }

    /**
     * @return array<string, ContactFieldDefinition>
     */
    public function definitionsByCode(?int $companyId = null): array
    {
        $companyId ??= TenantCompany::id();
        $this->ensureSystemFields($companyId);

        $map = [];
        foreach (ContactFieldDefinition::query()->where('company_id', $companyId)->get() as $definition) {
            $map[$definition->code] = $definition;
        }

        return $map;
    }

    public function ensureSystemFields(?int $companyId = null): void
    {
        $companyId ??= TenantCompany::id();

        foreach (ContactFieldCatalog::systemFields() as $field) {
            ContactFieldDefinition::query()->updateOrCreate(
                [
                    'company_id' => $companyId,
                    'code' => $field['code'],
                ],
                [
                    'label' => $field['label'],
                    'type' => $field['type'],
                    'section' => $field['section'],
                    'group' => $field['group'],
                    'is_system' => $field['is_system'],
                    'is_visible' => $field['is_visible'],
                    'sort_order' => $field['sort_order'],
                ],
            );
        }
    }

    /**
     * @param  array{
     *     label: string,
     *     type: string,
     *     section?: string,
     *     group?: string,
     *     options?: array<string, mixed>|null
     * }  $payload
     * @return array<string, mixed>
     */
    public function createCustom(array $payload, ?int $companyId = null): array
    {
        $companyId ??= TenantCompany::id();
        $this->ensureSystemFields($companyId);

        $label = trim($payload['label']);
        if ($label === '') {
            throw ValidationException::withMessages(['label' => 'Укажите название поля.']);
        }

        $type = ContactFieldType::normalize($payload['type']);
        $code = $this->uniqueCode($label, $companyId);
        $maxSort = (int) ContactFieldDefinition::query()
            ->where('company_id', $companyId)
            ->max('sort_order');

        $definition = ContactFieldDefinition::query()->create([
            'company_id' => $companyId,
            'code' => $code,
            'label' => $label,
            'type' => $type,
            'section' => $payload['section'] ?? 'contacts',
            'group' => $payload['group'] ?? 'additional',
            'is_system' => false,
            'is_visible' => true,
            'options' => $payload['options'] ?? null,
            'sort_order' => $maxSort + 10,
        ]);

        return $this->serialize($definition);
    }

    /**
     * @param  list<array{id: int, is_visible: bool}>  $visibility
     */
    public function syncVisibility(array $visibility, ?int $companyId = null): void
    {
        $companyId ??= TenantCompany::id();
        $this->ensureSystemFields($companyId);

        foreach ($visibility as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }

            ContactFieldDefinition::query()
                ->where('company_id', $companyId)
                ->whereKey($id)
                ->update(['is_visible' => (bool) ($row['is_visible'] ?? false)]);
        }
    }

    public function deleteCustom(int $definitionId, ?int $companyId = null): void
    {
        $companyId ??= TenantCompany::id();

        $definition = ContactFieldDefinition::query()
            ->where('company_id', $companyId)
            ->whereKey($definitionId)
            ->firstOrFail();

        if ($definition->is_system) {
            throw ValidationException::withMessages([
                'field' => 'Системное поле нельзя удалить.',
            ]);
        }

        $definition->delete();
    }

    /**
     * @return array<string, mixed>
     */
    public function serialize(ContactFieldDefinition $definition): array
    {
        return [
            'id' => $definition->id,
            'code' => $definition->code,
            'label' => $definition->label,
            'type' => $definition->type,
            'section' => $definition->section,
            'group' => $definition->group,
            'group_label' => ContactFieldCatalog::groupLabels()[$definition->group] ?? $definition->group,
            'is_system' => $definition->is_system,
            'is_visible' => $definition->is_visible,
            'options' => $definition->options,
            'sort_order' => $definition->sort_order,
        ];
    }

    private function uniqueCode(string $label, int $companyId): string
    {
        $base = Str::slug($label, '_');
        if ($base === '') {
            $base = 'field';
        }

        $code = $base;
        $suffix = 1;
        while (ContactFieldDefinition::query()->where('company_id', $companyId)->where('code', $code)->exists()) {
            $code = "{$base}_{$suffix}";
            $suffix++;
        }

        return $code;
    }
}
