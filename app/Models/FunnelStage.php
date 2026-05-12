<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Этап воронки продаж. Принадлежит ровно одной воронке (см. {@see Funnel}).
 * При удалении воронки удаляется каскадно (FK с `cascadeOnDelete`).
 */
final class FunnelStage extends Model
{
    use HasFactory;

    protected $fillable = [
        'funnel_id',
        'name',
        'color',
        'position',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'funnel_id' => 'integer',
            'position' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Funnel, FunnelStage>
     */
    public function funnel(): BelongsTo
    {
        return $this->belongsTo(Funnel::class);
    }

    /**
     * Отделы, в которых выбран этот этап (см. pivot `department_funnel_stage`).
     */
    public function departments(): BelongsToMany
    {
        return $this->belongsToMany(Department::class, 'department_funnel_stage')
            ->withTimestamps();
    }
}
