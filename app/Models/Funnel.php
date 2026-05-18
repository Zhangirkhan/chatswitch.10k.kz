<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Воронка продаж — справочник «контейнер для этапов». Этапы создаются вручную
 * через {@see FunnelStage}; порядок задаёт колонка `position` (asc).
 */
final class Funnel extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'name',
        'description',
        'color',
        'is_active',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'is_active' => 'boolean',
            'position' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Этапы воронки в порядке возрастания `position`. Сортировку держим на
     * уровне relation, чтобы у фронта всегда был стабильный порядок.
     *
     * @return HasMany<FunnelStage>
     */
    public function stages(): HasMany
    {
        return $this->hasMany(FunnelStage::class)->orderBy('position');
    }

    /**
     * @return HasMany<FunnelStageAiRule>
     */
    public function stageAiRules(): HasMany
    {
        return $this->hasMany(FunnelStageAiRule::class);
    }

    /**
     * @return HasOne<FunnelAiScenario, $this>
     */
    public function aiScenario(): HasOne
    {
        return $this->hasOne(FunnelAiScenario::class);
    }

    /**
     * Отделы, в которых эта воронка подключена (см. pivot `department_funnel`).
     */
    public function departments(): BelongsToMany
    {
        return $this->belongsToMany(Department::class, 'department_funnel')
            ->withTimestamps();
    }
}
