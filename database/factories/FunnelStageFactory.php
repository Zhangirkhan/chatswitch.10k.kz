<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Funnel;
use App\Models\FunnelStage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FunnelStage>
 */
final class FunnelStageFactory extends Factory
{
    protected $model = FunnelStage::class;

    public function definition(): array
    {
        return [
            'funnel_id' => Funnel::factory(),
            'name' => fake()->words(2, true),
            'color' => fake()->hexColor(),
            'stage_type' => 'other',
            'position' => fake()->numberBetween(1, 10),
            'is_active' => true,
            'wip_limit' => null,
        ];
    }
}
