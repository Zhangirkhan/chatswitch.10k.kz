<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EntityMemorySubjectType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class EntityMemory extends Model
{
    protected $fillable = [
        'tenant_company_id',
        'subject_type',
        'subject_id',
        'content',
        'content_hash',
        'updated_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'tenant_company_id' => 'integer',
            'subject_id' => 'integer',
            'updated_by_user_id' => 'integer',
        ];
    }

    public function subjectTypeEnum(): EntityMemorySubjectType
    {
        return EntityMemorySubjectType::from($this->subject_type);
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    /**
     * @return HasMany<EntityMemoryBackup>
     */
    public function backups(): HasMany
    {
        return $this->hasMany(EntityMemoryBackup::class)->orderByDesc('created_at');
    }
}
