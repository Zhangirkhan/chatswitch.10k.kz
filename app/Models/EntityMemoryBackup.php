<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class EntityMemoryBackup extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'entity_memory_id',
        'content',
        'content_hash',
        'created_by_user_id',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'entity_memory_id' => 'integer',
            'created_by_user_id' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function memory(): BelongsTo
    {
        return $this->belongsTo(EntityMemory::class, 'entity_memory_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
