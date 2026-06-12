<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class KnowledgeRetrievalLog extends Model
{
    protected $fillable = [
        'company_id',
        'ai_response_log_id',
        'chunk_id',
        'similarity',
        'domain',
    ];

    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'ai_response_log_id' => 'integer',
            'chunk_id' => 'integer',
            'similarity' => 'float',
        ];
    }

    /** @return BelongsTo<KnowledgeChunk, $this> */
    public function chunk(): BelongsTo
    {
        return $this->belongsTo(KnowledgeChunk::class, 'chunk_id');
    }
}
