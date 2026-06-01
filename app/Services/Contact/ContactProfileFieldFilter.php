<?php

declare(strict_types=1);

namespace App\Services\Contact;

use App\Models\Contact;
use App\Models\ContactFieldDefinition;
use App\Support\ContactFieldCatalog;
use App\Support\ContactFieldType;

final class ContactProfileFieldFilter
{
    public function __construct(
        private readonly ContactFieldDefinitionService $definitions,
        private readonly ContactFieldValueService $values,
    ) {}

    /**
     * @param  array<string, mixed>  $profile
     * @return array<string, mixed>
     */
    public function apply(Contact $contact, array $profile): array
    {
        $byCode = $this->definitions->definitionsByCode();
        $customValues = $this->values->valuesByDefinitionId($contact);

        $sections = is_array($profile['sections'] ?? null) ? $profile['sections'] : [];
        foreach ($sections as $index => $section) {
            if (! is_array($section)) {
                continue;
            }

            $key = (string) ($section['key'] ?? '');
            $fields = is_array($section['fields'] ?? null) ? $section['fields'] : [];
            $fields = $this->filterVisibleFields($fields, $byCode);
            $fields = $this->appendCustomFields($fields, $key, $byCode, $customValues);
            $sections[$index]['fields'] = $fields;
        }

        $profile['sections'] = $sections;
        $profile['field_definitions'] = array_values(array_map(
            fn (ContactFieldDefinition $row): array => $this->definitions->serialize($row),
            array_filter($byCode, fn (ContactFieldDefinition $row): bool => ! $row->is_system && $row->is_visible),
        ));

        return $profile;
    }

    /**
     * @param  list<array<string, mixed>>  $fields
     * @param  array<string, ContactFieldDefinition>  $byCode
     * @return list<array<string, mixed>>
     */
    private function filterVisibleFields(array $fields, array $byCode): array
    {
        $labelMap = ContactFieldCatalog::labelToCodeMap();
        $filtered = [];

        foreach ($fields as $field) {
            $code = (string) ($field['code'] ?? '');
            if ($code === '') {
                $label = (string) ($field['label'] ?? '');
                $code = $this->resolveCodeFromLabel($label, $labelMap);
                $field['code'] = $code !== '' ? $code : null;
            }

            if ($code === '' || $code === null) {
                $filtered[] = $field;

                continue;
            }

            $definition = $byCode[$code] ?? null;
            if ($definition !== null && ! $definition->is_visible) {
                continue;
            }

            $filtered[] = $field;
        }

        return $filtered;
    }

    /**
     * @param  list<array<string, mixed>>  $fields
     * @param  array<string, ContactFieldDefinition>  $byCode
     * @param  array<int, \App\Models\ContactFieldValue>  $customValues
     * @return list<array<string, mixed>>
     */
    private function appendCustomFields(array $fields, string $sectionKey, array $byCode, array $customValues): array
    {
        foreach ($byCode as $definition) {
            if ($definition->is_system || ! $definition->is_visible || $definition->section !== $sectionKey) {
                continue;
            }

            $value = $customValues[$definition->id] ?? null;
            $display = $this->values->displayValue($definition, $value);

            $fields[] = [
                'code' => $definition->code,
                'definition_id' => $definition->id,
                'label' => $definition->label,
                'value' => $display !== '' ? $display : '—',
                'raw_value' => $display,
                'source' => 'custom',
                'type' => $definition->type,
                'editable' => true,
                'options' => $definition->options,
            ];
        }

        return $fields;
    }

    /**
     * @param  array<string, string>  $labelMap
     */
    private function resolveCodeFromLabel(string $label, array $labelMap): string
    {
        if (isset($labelMap[$label])) {
            return $labelMap[$label];
        }

        foreach (['Телефон компании', 'Email компании', 'Сайт компании'] as $prefix) {
            if (str_starts_with($label, $prefix)) {
                return match ($prefix) {
                    'Телефон компании' => 'company_phone',
                    'Email компании' => 'company_email',
                    'Сайт компании' => 'company_website',
                    default => '',
                };
            }
        }

        return '';
    }
}
