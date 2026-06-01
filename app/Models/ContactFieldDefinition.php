<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class ContactFieldDefinition extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'company_id',
        'code',
        'label',
        'type',
        'section',
        'group',
        'is_system',
        'is_visible',
        'options',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'is_system' => 'boolean',
            'is_visible' => 'boolean',
            'options' => 'array',
            'sort_order' => 'integer',
        ];
    }

    /** @return HasMany<ContactFieldValue, $this> */
    public function values(): HasMany
    {
        return $this->hasMany(ContactFieldValue::class, 'field_definition_id');
    }
}
