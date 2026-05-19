<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\Chat;
use App\Models\WhatsappSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ChatAutomationDefaultsTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_direct_chat_has_ai_and_funnel_tracking_enabled_by_default(): void
    {
        $session = WhatsappSession::factory()->create();

        $chat = Chat::query()->create([
            'whatsapp_chat_id' => '77001112233@c.us',
            'whatsapp_session_id' => $session->id,
            'chat_name' => 'Клиент',
            'is_group' => false,
            'last_message_at' => now(),
        ]);

        $this->assertTrue($chat->ai_enabled);
        $this->assertSame('auto', $chat->ai_mode);
        $this->assertTrue($chat->funnel_tracking_enabled);
    }

    public function test_new_group_chat_does_not_force_ai_defaults(): void
    {
        $session = WhatsappSession::factory()->create();

        $chat = Chat::query()->create([
            'whatsapp_chat_id' => '120363000000000000@g.us',
            'whatsapp_session_id' => $session->id,
            'chat_name' => 'Группа',
            'is_group' => true,
            'last_message_at' => now(),
        ]);

        $this->assertFalse($chat->ai_enabled);
    }
}
