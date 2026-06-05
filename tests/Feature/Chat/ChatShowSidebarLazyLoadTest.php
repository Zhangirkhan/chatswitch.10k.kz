<?php

declare(strict_types=1);

namespace Tests\Feature\Chat;

use App\Models\Chat;
use App\Models\Message;
use App\Models\User;
use App\Models\WhatsappSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class ChatShowSidebarLazyLoadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['administrator', 'manager', 'employee'] as $role) {
            Role::findOrCreate($role);
        }
    }

    public function test_show_does_not_eager_load_full_sidebar_chat_list(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('administrator');

        $session = WhatsappSession::factory()->create();
        $chats = Chat::factory()->count(5)->create([
            'whatsapp_session_id' => $session->id,
            'last_message_at' => now(),
        ]);

        foreach ($chats as $chat) {
            Message::create([
                'chat_id' => $chat->id,
                'whatsapp_session_id' => $session->id,
                'direction' => 'inbound',
                'type' => 'chat',
                'body' => 'Test',
                'ack' => 'delivered',
                'message_timestamp' => now(),
            ]);
        }

        $target = $chats->first();
        $this->assertNotNull($target);

        $this->actingAs($admin)
            ->get(route('chats.show', $target))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Chats/Show')
                ->where('sidebarLazyLoad', true)
                ->where('chats.total', 5)
                ->has('chats.data', 1)
                ->where('chats.data.0.id', $target->id)
            );
    }
}
