<?php

declare(strict_types=1);

namespace App\Services\Contact;

use App\Models\Contact;
use App\Models\ContactFieldDefinition;
use App\Models\ContactFieldValue;
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
        $valuesByCode = $this->values->valuesByDefinitionCode($contact);

        $sections = is_array($profile['sections'] ?? null) ? $profile['sections'] : [];
        foreach ($sections as $index => $section) {
            if (! is_array($section)) {
                continue;
            }

            $key = (string) ($section['key'] ?? '');
            $fields = is_array($section['fields'] ?? null) ? $section['fields'] : [];
            $fields = $this->filterVisibleFields($fields, $byCode);
            $fields = $this->injectStoredSystemFields($contact, $fields, $key, $byCode, $valuesByCode);
            $fields = $this->appendCustomFields($fields, $key, $byCode, $customValues);
            $fields = $this->sortFields($fields, $byCode);
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
     * @param  array<string, ContactFieldValue>  $valuesByCode
     * @return list<array<string, mixed>>
     */
    private function injectStoredSystemFields(
        Contact $contact,
        array $fields,
        string $sectionKey,
        array $byCode,
        array $valuesByCode,
    ): array {
        $existingCodes = collect($fields)->pluck('code')->filter()->all();

        foreach ($byCode as $definition) {
            if (! $definition->is_system || ! $definition->is_visible || $definition->section !== $sectionKey) {
                continue;
            }

            if (! in_array($definition->code, ContactFieldCatalog::editableSystemCodes(), true)) {
                continue;
            }

            if (in_array($definition->code, $existingCodes, true)) {
                continue;
            }

            $fields[] = $this->buildStoredSystemField($contact, $definition, $valuesByCode[$definition->code] ?? null);
        }

        return $fields;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildStoredSystemField(
        Contact $contact,
        ContactFieldDefinition $definition,
        ?ContactFieldValue $value,
    ): array {
        if ($definition->code === 'photo') {
            return $this->buildPhotoField($contact, $definition, $value);
        }

        $display = $this->values->displayValue($definition, $value);

        return [
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

    /**
     * @return array<string, mixed>
     */
    private function buildPhotoField(
        Contact $contact,
        ContactFieldDefinition $definition,
        ?ContactFieldValue $value,
    ): array {
        $meta = $this->values->fileMeta($definition, $value);
        $previewUrl = $meta['preview_url'] ?? trim((string) ($contact->profile_picture_url ?? ''));
        $previewUrl = $previewUrl !== '' ? $previewUrl : null;
        $source = ($meta['preview_url'] ?? null) !== null ? 'custom' : 'crm';

        return [
            'code' => 'photo',
            'definition_id' => $definition->id,
            'label' => $definition->label,
            'value' => $previewUrl ? 'Загружено' : '—',
            'raw_value' => $meta['raw_value'],
            'preview_url' => $previewUrl,
            'source' => $source,
            'type' => ContactFieldType::PHOTO,
            'editable' => true,
            'options' => $definition->options,
            'value_json' => $meta['value_json'],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $fields
     * @param  array<string, ContactFieldDefinition>  $byCode
     * @param  array<int, ContactFieldValue>  $customValues
     * @return list<array<string, mixed>>
     */
    private function appendCustomFields(array $fields, string $sectionKey, array $byCode, array $customValues): array
    {
        foreach ($byCode as $definition) {
            if ($definition->is_system || ! $definition->is_visible || $definition->section !== $sectionKey) {
                continue;
            }

            $value = $customValues[$definition->id] ?? null;
            $fields[] = $this->buildCustomField($definition, $value);
        }

        return $fields;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildCustomField(ContactFieldDefinition $definition, ?ContactFieldValue $value): array
    {
        if (in_array($definition->type, [ContactFieldType::PHOTO, ContactFieldType::FILE], true)) {
            $meta = $this->values->fileMeta($definition, $value);

            return [
                'code' => $definition->code,
                'definition_id' => $definition->id,
                'label' => $definition->label,
                'value' => $meta['preview_url'] ? ($meta['raw_value'] ?: 'Файл') : '—',
                'raw_value' => $meta['raw_value'],
                'preview_url' => $meta['preview_url'],
                'source' => 'custom',
                'type' => $definition->type,
                'editable' => true,
                'options' => $definition->options,
                'value_json' => $meta['value_json'],
            ];
        }

        $display = $this->values->displayValue($definition, $value);
        $rawValue = $display;
        if ($definition->type === ContactFieldType::MONEY && $value !== null && is_array($value->value_json)) {
            $rawValue = $value->value_json;
        }

        return [
            'code' => $definition->code,
            'definition_id' => $definition->id,
            'label' => $definition->label,
            'value' => $display !== '' ? $display : '—',
            'raw_value' => $rawValue,
            'source' => 'custom',
            'type' => $definition->type,
            'editable' => true,
            'options' => $definition->options,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $fields
     * @param  array<string, ContactFieldDefinition>  $byCode
     * @return list<array<string, mixed>>
     */
    private function sortFields(array $fields, array $byCode): array
    {
        usort($fields, function (array $left, array $right) use ($byCode): int {
            $leftOrder = $this->sortOrderForField($left, $byCode);
            $rightOrder = $this->sortOrderForField($right, $byCode);

            return $leftOrder <=> $rightOrder;
        });

        return $fields;
    }

    /**
     * @param  array<string, mixed>  $field
     * @param  array<string, ContactFieldDefinition>  $byCode
     */
    private function sortOrderForField(array $field, array $byCode): int
    {
        $code = (string) ($field['code'] ?? '');
        if ($code !== '' && isset($byCode[$code])) {
            return (int) $byCode[$code]->sort_order;
        }

        return 999;
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
