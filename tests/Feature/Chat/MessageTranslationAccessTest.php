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

final class MessageTranslationAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['administrator', 'manager', 'employee'] as $r) {
            Role::findOrCreate($r);
        }
    }

    public function test_employee_cannot_translate_message_in_inaccessible_chat(): void
    {
        $user = User::factory()->create();
        $user->assignRole('employee');

        $session = WhatsappSession::factory()->create();
        $chat = Chat::factory()->create(['whatsapp_session_id' => $session->id]);
        $message = Message::query()->create([
            'chat_id' => $chat->id,
            'whatsapp_session_id' => $session->id,
            'whatsapp_message_id' => 'wamid.x',
            'direction' => 'inbound',
            'type' => 'chat',
            'body' => 'secret text',
            'sender_phone' => '77011234567',
            'sender_name' => 'Client',
        ]);

        $this->actingAs($user)
            ->postJson(route('messages.translate', $message), ['lang' => 'en'])
            ->assertForbidden();
    }
}
