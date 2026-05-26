<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\WhatsappSession;
use Database\Factories\Concerns\UsesTenantCompany;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WhatsappSession>
 */
final class WhatsappSessionFactory extends Factory
{
    use UsesTenantCompany;

    protected $model = WhatsappSession::class;

    public function definition(): array
    {
        return [
            'company_id' => $this->tenantCompanyId(),
            'session_name' => 'wa-'.fake()->unique()->uuid(),
            'display_name' => fake()->company(),
            'phone_number' => '77'.fake()->numerify('#########'),
            'status' => 'connected',
            'is_active' => true,
            'connected_at' => now(),
        ];
    }
}
