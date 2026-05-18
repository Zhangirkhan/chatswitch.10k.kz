<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class KnowledgeChunk extends Model
{
    public const TYPE_PRODUCT = 'product';

    public const TYPE_SERVICE = 'service';

    public const TYPE_RULE = 'rule';

    protected $fillable = [
        'company_id',
        'source_type',
        'source_id',
        'content_text',
        'display_line',
        'content_hash',
        'embedding',
    ];

    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'source_id' => 'integer',
            'embedding' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Company, KnowledgeChunk>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
