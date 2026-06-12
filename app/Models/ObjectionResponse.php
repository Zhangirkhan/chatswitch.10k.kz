<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ObjectionResponse extends Model
{
    protected $fillable = [
        'objection_cluster_id',
        'response_text',
        'usage_count',
        'win_count',
        'loss_count',
        'source',
    ];

    protected function casts(): array
    {
        return [
            'objection_cluster_id' => 'integer',
            'usage_count' => 'integer',
            'win_count' => 'integer',
            'loss_count' => 'integer',
        ];
    }

    /** @return BelongsTo<ObjectionCluster, $this> */
    public function cluster(): BelongsTo
    {
        return $this->belongsTo(ObjectionCluster::class, 'objection_cluster_id');
    }
}
