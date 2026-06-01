<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ContactFieldValue extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'company_id',
        'contact_id',
        'field_definition_id',
        'value_text',
        'value_json',
    ];

    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'contact_id' => 'integer',
            'field_definition_id' => 'integer',
            'value_json' => 'array',
        ];
    }

    /** @return BelongsTo<ContactFieldDefinition, $this> */
    public function definition(): BelongsTo
    {
        return $this->belongsTo(ContactFieldDefinition::class, 'field_definition_id');
    }

    /** @return BelongsTo<Contact, $this> */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }
}
