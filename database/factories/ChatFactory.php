<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Chat;
use App\Models\WhatsappSession;
use Database\Factories\Concerns\UsesTenantCompany;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Chat>
 */
final class ChatFactory extends Factory
{
    use UsesTenantCompany;

    protected $model = Chat::class;

    public function definition(): array
    {
        return [
            'company_id' => $this->tenantCompanyId(),
            'whatsapp_chat_id' => '77'.fake()->unique()->numerify('#########').'@c.us',
            'whatsapp_session_id' => WhatsappSession::factory(),
            'contact_id' => null,
            'chat_name' => fake()->name(),
            'is_group' => false,
            'is_archived' => false,
            'is_pinned' => false,
            'is_muted' => false,
            'is_favorite' => false,
            'ai_enabled' => true,
            'ai_mode' => 'auto',
            'funnel_tracking_enabled' => true,
            'unread_count' => 0,
            'last_message_at' => now(),
        ];
    }
}
