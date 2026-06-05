<?php

declare(strict_types=1);

namespace Tests\Feature\Contact;

use App\Enums\EntityMemorySubjectType;
use App\Models\Chat;
use App\Models\Contact;
use App\Models\EntityMemory;
use App\Models\Message;
use App\Models\User;
use App\Models\WhatsappSession;
use App\Services\Memory\EntityMemoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class ClientsClearDataTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['administrator', 'manager', 'employee'] as $role) {
            Role::findOrCreate($role);
        }
    }

    public function test_administrator_can_clear_client_memory_and_chat_from_clients_hub(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('administrator');

        $session = WhatsappSession::factory()->create();
        $contact = Contact::factory()->create(['name' => 'Клиент']);
        $chat = Chat::factory()->create([
            'contact_id' => $contact->id,
            'whatsapp_session_id' => $session->id,
            'is_group' => false,
            'last_message_at' => now(),
        ]);

        app(EntityMemoryService::class)->update(
            EntityMemorySubjectType::Contact,
            $contact->id,
            'Клиент интересуется рассрочкой.',
            $admin,
        );

        Message::create([
            'chat_id' => $chat->id,
            'whatsapp_session_id' => $session->id,
            'direction' => 'inbound',
            'type' => 'chat',
            'body' => 'Привет',
            'ack' => 'delivered',
            'message_timestamp' => now(),
        ]);

        $this->actingAs($admin)
            ->post(route('clients.clear-memory', $contact))
            ->assertOk()
            ->assertJson(['success' => true]);

        $memory = EntityMemory::query()
            ->where('subject_type', EntityMemorySubjectType::Contact->value)
            ->where('subject_id', $contact->id)
            ->first();

        $this->assertNotNull($memory);
        $this->assertStringContainsString('Клиент', (string) $memory->content);
        $this->assertStringNotContainsString('рассрочкой', (string) $memory->content);

        $this->actingAs($admin)
            ->post(route('clients.clear-chat', ['contact' => $contact, 'chat' => $chat]))
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertSame(0, Message::query()->where('chat_id', $chat->id)->count());
        $chat->refresh();
        $this->assertNull($chat->last_message_at);
    }

    public function test_employee_cannot_clear_client_data(): void
    {
        $employee = User::factory()->create();
        $employee->assignRole('employee');

        $session = WhatsappSession::factory()->create();
        $contact = Contact::factory()->create();
        $chat = Chat::factory()->create([
            'contact_id' => $contact->id,
            'whatsapp_session_id' => $session->id,
            'is_group' => false,
        ]);

        $this->actingAs($employee)
            ->post(route('clients.clear-memory', $contact))
            ->assertForbidden();

        $this->actingAs($employee)
            ->post(route('clients.clear-chat', ['contact' => $contact, 'chat' => $chat]))
            ->assertForbidden();
    }
}
