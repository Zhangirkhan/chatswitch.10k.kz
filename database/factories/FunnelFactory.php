<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Funnel;
use Database\Factories\Concerns\UsesTenantCompany;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Funnel>
 */
final class FunnelFactory extends Factory
{
    use UsesTenantCompany;

    protected $model = Funnel::class;

    public function definition(): array
    {
        return [
            'company_id' => $this->tenantCompanyId(),
            'name' => fake()->words(2, true),
            'description' => fake()->optional()->sentence(),
            'color' => fake()->hexColor(),
            'is_active' => true,
            'position' => fake()->numberBetween(0, 10),
        ];
    }
}
