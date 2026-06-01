<?php

declare(strict_types=1);

namespace App\Services\Contact;

use App\Models\Contact;
use App\Models\ContactFieldDefinition;
use App\Models\ContactFieldValue;
use App\Support\ContactFieldCatalog;
use App\Support\ContactFieldType;
use App\Support\TenantCompany;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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
     * @return array<int, ContactFieldValue>
     */
    public function valuesByDefinitionCode(Contact $contact): array
    {
        $map = [];
        foreach ($this->loadValues($contact) as $value) {
            $code = $value->definition?->code;
            if ($code !== null && $code !== '') {
                $map[$code] = $value;
            }
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
     * @return list<array{label: string, value: string}>
     */
    public function contextLines(Contact $contact): array
    {
        $lines = [];
        foreach ($this->loadValues($contact) as $value) {
            $definition = $value->definition;
            if ($definition === null) {
                continue;
            }

            $display = $this->displayValue($definition, $value);
            if ($display === '') {
                continue;
            }

            $lines[] = [
                'label' => $definition->label,
                'value' => $display,
            ];
        }

        return $lines;
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

                if ($definition === null || ! $this->canPersistDefinition($definition)) {
                    continue;
                }

                [$valueText, $valueJson] = $this->normalizeInput($definition, $row['value'] ?? null);

                if ($valueText === null && $valueJson === null) {
                    ContactFieldValue::query()
                        ->where('contact_id', $contact->id)
                        ->where('field_definition_id', $definition->id)
                        ->delete();

                    if ($definition->code === 'photo') {
                        $contact->profile_picture_url = null;
                        $contact->saveQuietly();
                    }

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

    /**
     * @return array{profile_picture_url: string|null, value_json: array<string, mixed>}
     */
    public function uploadForDefinition(
        Contact $contact,
        ContactFieldDefinition $definition,
        UploadedFile $file,
    ): array {
        if (! in_array($definition->type, [ContactFieldType::PHOTO, ContactFieldType::FILE], true)) {
            throw ValidationException::withMessages(['file' => 'Поле не поддерживает загрузку файлов.']);
        }

        if (! $this->canPersistDefinition($definition)) {
            throw ValidationException::withMessages(['file' => 'Поле недоступно для редактирования.']);
        }

        $companyId = TenantCompany::id();
        $directory = "contact-fields/{$companyId}/{$contact->id}";
        $path = $file->store($directory, 'public');
        $url = Storage::disk('public')->url($path);

        $payload = [
            'path' => $path,
            'url' => $url,
            'original_name' => $file->getClientOriginalName(),
            'mime' => (string) ($file->getMimeType() ?: 'application/octet-stream'),
            'size' => $file->getSize(),
        ];

        ContactFieldValue::query()->updateOrCreate(
            [
                'contact_id' => $contact->id,
                'field_definition_id' => $definition->id,
            ],
            [
                'company_id' => $companyId,
                'value_text' => null,
                'value_json' => $payload,
            ],
        );

        if ($definition->type === ContactFieldType::PHOTO) {
            $contact->profile_picture_url = $url;
            $contact->saveQuietly();
        }

        return [
            'profile_picture_url' => $contact->profile_picture_url,
            'value_json' => $payload,
        ];
    }

    public function canPersistDefinition(ContactFieldDefinition $definition): bool
    {
        if (! $definition->is_system) {
            return true;
        }

        return in_array($definition->code, ContactFieldCatalog::editableSystemCodes(), true);
    }

    public function displayValue(ContactFieldDefinition $definition, ?ContactFieldValue $value): string
    {
        if ($value === null) {
            return '';
        }

        if (in_array($definition->type, [ContactFieldType::PHOTO, ContactFieldType::FILE], true)
            && is_array($value->value_json)) {
            return trim((string) ($value->value_json['original_name'] ?? $value->value_json['url'] ?? ''));
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
     * @return array{preview_url: string|null, raw_value: string, value_json: array<string, mixed>|null}
     */
    public function fileMeta(ContactFieldDefinition $definition, ?ContactFieldValue $value): array
    {
        if ($value === null || ! is_array($value->value_json)) {
            return [
                'preview_url' => null,
                'raw_value' => '',
                'value_json' => null,
            ];
        }

        $url = trim((string) ($value->value_json['url'] ?? ''));

        return [
            'preview_url' => $url !== '' ? $url : null,
            'raw_value' => trim((string) ($value->value_json['original_name'] ?? $url)),
            'value_json' => $value->value_json,
        ];
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
