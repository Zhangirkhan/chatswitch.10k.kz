<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A short label attached to a Contact.
 * May be set by a human operator (source='manual') or by the AI (source='ai').
 *
 * @property int         $id
 * @property int         $company_id
 * @property int         $contact_id
 * @property string      $name
 * @property string      $source
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
final class ContactTag extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'company_id',
        'contact_id',
        'name',
        'source',
    ];

    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_AI = 'ai';

    public const SOURCE_IMPORT = 'import';

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }
}
