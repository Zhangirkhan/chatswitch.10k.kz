<?php

declare(strict_types=1);

namespace App\Services\Contact;

use App\Models\Contact;
use App\Models\ContactFieldDefinition;
use App\Models\ContactFieldValue;
use App\Support\ContactFieldType;
use App\Support\TenantCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ContactFieldValueService
{
    public function __construct(
        private readonly ContactFieldDefinitionService $definitions,
    ) {}

    /**
     * @return array<int, ContactFieldValue>
     */
    public function valuesByDefinitionId(Contact $contact): array
    {
        $map = [];
        foreach ($this->loadValues($contact) as $value) {
            $map[$value->field_definition_id] = $value;
        }

        return $map;
    }

    /**
     * @return list<ContactFieldValue>
     */
    public function loadValues(Contact $contact): array
    {
        return ContactFieldValue::query()
            ->where('contact_id', $contact->id)
            ->with('definition')
            ->get()
            ->all();
    }

    /**
     * @param  list<array{field_id: int, value?: mixed}>  $fields
     */
    public function upsertForContact(Contact $contact, array $fields): void
    {
        $companyId = TenantCompany::id();
        $this->definitions->ensureSystemFields($companyId);

        DB::transaction(function () use ($contact, $fields, $companyId): void {
            foreach ($fields as $row) {
                $fieldId = (int) ($row['field_id'] ?? 0);
                if ($fieldId <= 0) {
                    continue;
                }

                $definition = ContactFieldDefinition::query()
                    ->where('company_id', $companyId)
                    ->whereKey($fieldId)
                    ->first();

                if ($definition === null || $definition->is_system) {
                    continue;
                }

                [$valueText, $valueJson] = $this->normalizeInput($definition, $row['value'] ?? null);

                if ($valueText === null && $valueJson === null) {
                    ContactFieldValue::query()
                        ->where('contact_id', $contact->id)
                        ->where('field_definition_id', $definition->id)
                        ->delete();

                    continue;
                }

                ContactFieldValue::query()->updateOrCreate(
                    [
                        'contact_id' => $contact->id,
                        'field_definition_id' => $definition->id,
                    ],
                    [
                        'company_id' => $companyId,
                        'value_text' => $valueText,
                        'value_json' => $valueJson,
                    ],
                );
            }
        });
    }

    public function displayValue(ContactFieldDefinition $definition, ?ContactFieldValue $value): string
    {
        if ($value === null) {
            return '';
        }

        if ($definition->type === ContactFieldType::MONEY && is_array($value->value_json)) {
            $amount = trim((string) ($value->value_json['amount'] ?? ''));
            $currency = trim((string) ($value->value_json['currency'] ?? 'KZT'));

            return $amount !== '' ? "{$amount} {$currency}" : '';
        }

        if ($definition->type === ContactFieldType::BOOLEAN) {
            $raw = $value->value_text ?? ($value->value_json['value'] ?? null);

            return filter_var($raw, FILTER_VALIDATE_BOOLEAN) ? 'Да' : 'Нет';
        }

        return trim((string) ($value->value_text ?? ''));
    }

    /**
     * @return array{0: string|null, 1: array<string, mixed>|null}
     */
    private function normalizeInput(ContactFieldDefinition $definition, mixed $raw): array
    {
        if ($raw === null || $raw === '') {
            return [null, null];
        }

        if ($definition->type === ContactFieldType::MONEY) {
            if (! is_array($raw)) {
                throw ValidationException::withMessages(['value' => 'Укажите сумму и валюту.']);
            }

            $amount = trim((string) ($raw['amount'] ?? ''));
            if ($amount === '') {
                return [null, null];
            }

            return [null, [
                'amount' => $amount,
                'currency' => trim((string) ($raw['currency'] ?? 'KZT')) ?: 'KZT',
            ]];
        }

        if ($definition->type === ContactFieldType::BOOLEAN) {
            $bool = filter_var($raw, FILTER_VALIDATE_BOOLEAN);

            return [$bool ? '1' : '0', ['value' => $bool]];
        }

        if (is_array($raw)) {
            return [null, $raw];
        }

        $text = trim((string) $raw);
        if ($text === '') {
            return [null, null];
        }

        return [$text, null];
    }
}
