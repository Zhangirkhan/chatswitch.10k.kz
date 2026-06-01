<?php

declare(strict_types=1);

namespace App\Services\Contact;

use App\Enums\EntityMemorySubjectType;
use App\Models\Contact;
use App\Models\ContactFieldDefinition;
use App\Support\ContactFieldType;
use App\Support\ContactListFilters;
use App\Support\TenantCompany;
use Illuminate\Database\Eloquent\Builder;

final class ContactListFilterService
{
    /** @var list<string> */
    private const MEMORY_FIELD_CODES = ['city', 'address', 'district'];

    public function __construct(
        private readonly ContactFieldDefinitionService $definitions,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function filterableFieldDefinitions(?int $companyId = null): array
    {
        $skipCodes = [
            'photo',
            'memory',
            'open_tasks',
            'wa_channels',
            'contact_id',
            'contact_whatsapp_id',
            'funnel',
            'deal_progress',
            'messenger',
            'company_phone',
            'company_email',
            'company_website',
            'lead_id',
        ];

        $skipTypes = [
            ContactFieldType::PHOTO,
            ContactFieldType::FILE,
        ];

        return array_values(array_filter(
            $this->definitions->listForCompany($companyId),
            static function (array $field) use ($skipCodes, $skipTypes): bool {
                if (($field['group'] ?? '') === 'hidden') {
                    return false;
                }
                if (in_array($field['code'] ?? '', $skipCodes, true)) {
                    return false;
                }

                return ! in_array($field['type'] ?? '', $skipTypes, true);
            },
        ));
    }

    /**
     * @param  Builder<Contact>  $query
     */
    public function apply(Builder $query, ContactListFilters $filters): void
    {
        if ($filters->isEmpty()) {
            return;
        }

        $companyId = TenantCompany::id();
        $byCode = $this->definitions->definitionsByCode($companyId);

        foreach ($filters->values as $code => $value) {
            $this->applyOne($query, $code, $value, $byCode[$code] ?? null);
        }
    }

    /**
     * @param  Builder<Contact>  $query
     */
    private function applyOne(Builder $query, string $code, string $value, ?ContactFieldDefinition $definition): void
    {
        if ($code === 'name') {
            $query->where(function (Builder $q) use ($value): void {
                $like = '%'.$value.'%';
                $q->where('name', 'like', $like)
                    ->orWhere('push_name', 'like', $like);
            });

            return;
        }

        if ($code === 'phone') {
            $digits = preg_replace('/\D/', '', $value);
            $query->where(function (Builder $q) use ($value, $digits): void {
                $like = '%'.$value.'%';
                $q->where('phone_number', 'like', $like)
                    ->orWhere('whatsapp_id', 'like', $like);
                if (is_string($digits) && $digits !== '') {
                    $q->orWhere('phone_number', 'like', '%'.$digits.'%')
                        ->orWhere('whatsapp_id', 'like', '%'.$digits.'%');
                }
            });

            return;
        }

        if ($code === 'companies') {
            $query->whereHas('companies', fn (Builder $q) => $q->where('name', 'like', '%'.$value.'%'));

            return;
        }

        if ($code === 'funnel_stage') {
            if (ctype_digit($value)) {
                $stageId = (int) $value;
                $query->whereHas('chats', fn (Builder $q) => $q
                    ->where('is_group', false)
                    ->where('funnel_stage_id', $stageId));

                return;
            }

            $query->whereHas('chats', fn (Builder $q) => $q
                ->where('is_group', false)
                ->whereHas('funnelStage', fn (Builder $stageQuery) => $stageQuery->where('name', 'like', '%'.$value.'%')));

            return;
        }

        if ($code === 'assignee') {
            if (ctype_digit($value)) {
                $userId = (int) $value;
                $query->whereHas('chats', fn (Builder $q) => $q
                    ->where('is_group', false)
                    ->whereHas('assignments', fn (Builder $assigneeQuery) => $assigneeQuery->where('user_id', $userId)));

                return;
            }

            $query->whereHas('chats', fn (Builder $q) => $q
                ->where('is_group', false)
                ->whereHas('assignments.user', fn (Builder $userQuery) => $userQuery->where('name', 'like', '%'.$value.'%')));

            return;
        }

        if ($code === 'b2b_type') {
            $normalized = mb_strtolower($value);
            if (in_array($normalized, ['b2b', 'бизнес', 'business', '1', 'да', 'yes'], true)) {
                $query->where('is_business', true);

                return;
            }
            if (in_array($normalized, ['b2c', 'физ', 'физлицо', '0', 'нет', 'no'], true)) {
                $query->where('is_business', false);

                return;
            }

            return;
        }

        if (in_array($code, self::MEMORY_FIELD_CODES, true)) {
            $this->applyMemoryFieldFilter($query, $code, $value);

            return;
        }

        if ($definition !== null) {
            $this->applyStoredFieldFilter($query, $definition, $value);
        }
    }

    /**
     * @param  Builder<Contact>  $query
     */
    private function applyMemoryFieldFilter(Builder $query, string $code, string $value): void
    {
        $companyId = TenantCompany::id();

        $memoryContactIds = \App\Models\EntityMemory::query()
            ->where('tenant_company_id', $companyId)
            ->where('subject_type', EntityMemorySubjectType::Contact->value)
            ->where('content', 'like', '%'.$value.'%')
            ->pluck('subject_id')
            ->all();

        $query->where(function (Builder $outer) use ($code, $value, $memoryContactIds): void {
            if ($memoryContactIds !== []) {
                $outer->whereIn('id', $memoryContactIds);
            }

            $outer->orWhereHas('fieldValues', function (Builder $fieldQuery) use ($code, $value): void {
                $fieldQuery
                    ->whereHas('definition', fn (Builder $def) => $def->where('code', $code))
                    ->where('value_text', 'like', '%'.$value.'%');
            });
        });
    }

    /**
     * @param  Builder<Contact>  $query
     */
    private function applyStoredFieldFilter(Builder $query, ContactFieldDefinition $definition, string $value): void
    {
        $type = ContactFieldType::normalize($definition->type);

        $query->whereHas('fieldValues', function (Builder $fieldQuery) use ($definition, $value, $type): void {
            $fieldQuery->where('field_definition_id', $definition->id);

            match ($type) {
                ContactFieldType::NUMBER, ContactFieldType::MONEY => $fieldQuery->where('value_text', $value),
                ContactFieldType::BOOLEAN => $fieldQuery->where(
                    'value_text',
                    in_array(mb_strtolower($value), ['1', 'true', 'да', 'yes'], true) ? '1' : '0',
                ),
                ContactFieldType::LIST => $fieldQuery->where(function (Builder $listQuery) use ($value): void {
                    $listQuery->where('value_text', $value)
                        ->orWhere('value_text', 'like', '%'.$value.'%')
                        ->orWhereJsonContains('value_json', $value);
                }),
                default => $fieldQuery->where('value_text', 'like', '%'.$value.'%'),
            };
        });
    }
}
