<?php

declare(strict_types=1);

namespace App\Http\Requests\Contact;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateContactFieldsRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $fields = $this->input('fields');
        if (! is_array($fields)) {
            return;
        }

        $normalized = [];
        foreach ($fields as $field) {
            if (! is_array($field)) {
                continue;
            }

            $fieldId = $field['field_id'] ?? $field['definition_id'] ?? $field['id'] ?? null;
            if ($fieldId === null || $fieldId === '') {
                continue;
            }

            $normalized[] = [
                'field_id' => (int) $fieldId,
                'value' => $field['value'] ?? null,
            ];
        }

        $this->merge(['fields' => $normalized]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'fields' => ['required', 'array', 'min:1'],
            'fields.*.field_id' => ['required', 'integer', 'min:1'],
            'fields.*.value' => ['nullable'],
        ];
    }

    /**
     * @return list<array{field_id: int, value?: mixed}>
     */
    public function normalizedFields(): array
    {
        /** @var list<array{field_id: int, value?: mixed}> */
        return $this->validated('fields');
    }
}
