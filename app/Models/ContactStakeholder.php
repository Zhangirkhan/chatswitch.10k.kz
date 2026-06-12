<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ContactStakeholder extends Model
{
    public const ROLE_DECISION_MAKER = 'decision_maker';

    public const ROLE_INFLUENCER = 'influencer';

    public const ROLE_BLOCKER = 'blocker';

    public const ROLE_FINANCE = 'finance';

    public const ROLE_USER = 'user';

    public const SOURCE_AI = 'ai_extraction';

    public const SOURCE_MANAGER = 'manager';

    protected $fillable = [
        'company_id',
        'account_contact_id',
        'stakeholder_contact_id',
        'role',
        'influence',
        'notes',
        'detected_at',
        'source',
    ];

    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'account_contact_id' => 'integer',
            'stakeholder_contact_id' => 'integer',
            'influence' => 'integer',
            'detected_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Contact, $this> */
    public function accountContact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'account_contact_id');
    }

    /** @return BelongsTo<Contact, $this> */
    public function stakeholderContact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'stakeholder_contact_id');
    }
}
