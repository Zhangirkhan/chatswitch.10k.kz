<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class KnowledgeChunkStat extends Model
{
    protected $primaryKey = 'chunk_id';

    public $incrementing = false;

    protected $fillable = [
        'chunk_id',
        'company_id',
        'retrieval_count',
        'reply_count',
        'won_after_use',
        'lost_after_use',
        'manager_override_count',
        'last_retrieved_at',
        'quality_score',
        'computed_at',
    ];

    protected function casts(): array
    {
        return [
            'chunk_id' => 'integer',
            'company_id' => 'integer',
            'retrieval_count' => 'integer',
            'reply_count' => 'integer',
            'won_after_use' => 'integer',
            'lost_after_use' => 'integer',
            'manager_override_count' => 'integer',
            'last_retrieved_at' => 'datetime',
            'quality_score' => 'float',
            'computed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<KnowledgeChunk, $this> */
    public function chunk(): BelongsTo
    {
        return $this->belongsTo(KnowledgeChunk::class, 'chunk_id');
    }
}
