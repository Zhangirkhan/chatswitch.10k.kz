<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AiOrchestratorRun;
use App\Models\Chat;
use App\Models\Message;
use Database\Factories\Concerns\UsesTenantCompany;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiOrchestratorRun>
 */
final class AiOrchestratorRunFactory extends Factory
{
    use UsesTenantCompany;

    protected $model = AiOrchestratorRun::class;

    public function definition(): array
    {
        return [
            'company_id' => $this->tenantCompanyId(),
            'chat_id' => Chat::factory(),
            'trigger_message_id' => null,
            'funnel_id' => null,
            'funnel_stage_id' => null,
            'status' => AiOrchestratorRun::STATUS_PENDING,
            'confidence' => null,
            'reason' => null,
            'context' => null,
            'plan' => null,
            'error' => null,
            'started_at' => null,
            'completed_at' => null,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (AiOrchestratorRun $run): void {
            if ($run->trigger_message_id !== null || $run->chat_id === null) {
                return;
            }

            $message = Message::factory()->create([
                'chat_id' => $run->chat_id,
            ]);
            $run->trigger_message_id = $message->id;
        });
    }
}
