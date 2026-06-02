<?php

declare(strict_types=1);

namespace Tests\Feature\Contact;

use App\Models\Chat;
use App\Models\ChatAssignment;
use App\Models\Contact;
use App\Models\User;
use App\Models\WhatsappSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class ClientsHubTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['administrator', 'manager', 'employee'] as $role) {
            Role::findOrCreate($role);
        }
    }

    public function test_clients_index_is_available_for_employee_with_chat_access(): void
    {
        $employee = User::factory()->create();
        $employee->assignRole('employee');

        $session = WhatsappSession::factory()->create();
        $contact = Contact::factory()->create(['name' => 'Клиент']);
        $chat = Chat::factory()->create([
            'contact_id' => $contact->id,
            'whatsapp_session_id' => $session->id,
            'is_group' => false,
        ]);

        ChatAssignment::query()->create([
            'chat_id' => $chat->id,
            'user_id' => $employee->id,
            'assigned_by' => $employee->id,
        ]);

        $this->actingAs($employee)
            ->get(route('clients.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Clients/Index')
                ->has('clients.data', 1)
                ->where('clients.data.0.id', $contact->id));
    }

    public function test_clients_index_denies_employee_without_chat_access(): void
    {
        $employee = User::factory()->create();
        $employee->assignRole('employee');

        $session = WhatsappSession::factory()->create();
        Contact::factory()->create(['name' => 'Hidden']);
        Chat::factory()->create([
            'contact_id' => Contact::factory()->create()->id,
            'whatsapp_session_id' => $session->id,
            'is_group' => false,
        ]);

        $this->actingAs($employee)
            ->get(route('clients.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Clients/Index')
                ->has('clients.data', 0));
    }

    public function test_clients_profile_returns_six_sections_with_finance_placeholder(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('administrator');
        $session = WhatsappSession::factory()->create();
        $contact = Contact::factory()->create(['name' => 'Айгуль', 'phone_number' => '77001112233']);
        Chat::factory()->create([
            'contact_id' => $contact->id,
            'whatsapp_session_id' => $session->id,
            'is_group' => false,
        ]);

        $response = $this->actingAs($admin)
            ->getJson(route('clients.profile', $contact))
            ->assertOk();

        $sections = $response->json('profile.sections');
        $this->assertIsArray($sections);
        $this->assertCount(6, $sections);

        $keys = collect($sections)->pluck('key')->all();
        $this->assertSame(
            ['basic', 'contacts', 'finance', 'b2b', 'history', 'tasks_notes'],
            $keys,
        );

        $finance = collect($sections)->firstWhere('key', 'finance');
        $this->assertSame('unavailable', $finance['status'] ?? null);
        $this->assertStringContainsString('интеграции', (string) ($finance['message'] ?? ''));
    }

    public function test_settings_clients_redirects_to_clients_index(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('administrator');

        $this->actingAs($admin)
            ->get(route('settings.clients', ['tab' => 'companies']))
            ->assertRedirect(route('clients.index', ['tab' => 'companies']));
    }

    public function test_contacts_index_redirects_to_clients_index(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('administrator');

        $this->actingAs($admin)
            ->get(route('contacts.index', ['search' => 'test']))
            ->assertRedirect(route('clients.index', ['search' => 'test']));
    }

    public function test_clients_index_paginates_without_loading_all_rows_on_page_two(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('administrator');
        $session = WhatsappSession::factory()->create();

        for ($i = 1; $i <= 25; $i++) {
            $contact = Contact::factory()->create([
                'name' => "Client {$i}",
                'phone_number' => '7700'.str_pad((string) $i, 7, '0', STR_PAD_LEFT),
            ]);

            Chat::factory()->create([
                'contact_id' => $contact->id,
                'whatsapp_session_id' => $session->id,
                'is_group' => false,
                'last_message_at' => now()->subMinutes($i),
            ]);
        }

        $this->actingAs($admin)
            ->get(route('clients.index', ['clients_page' => 2]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Clients/Index')
                ->where('clients.current_page', 2)
                ->where('clients.last_page', 2)
                ->where('clients.total', 25)
                ->has('clients.data', 5));
    }

    public function test_clients_index_groups_duplicate_phone_digits_into_one_client(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('administrator');
        $session = WhatsappSession::factory()->create();

        $primary = Contact::factory()->create([
            'name' => 'Merged Client',
            'phone_number' => '77001112233',
        ]);
        $duplicate = Contact::factory()->create([
            'phone_number' => '77001112233',
            'whatsapp_id' => '77001112233@c.us',
        ]);

        Chat::factory()->create([
            'contact_id' => $primary->id,
            'whatsapp_session_id' => $session->id,
            'is_group' => false,
        ]);
        Chat::factory()->create([
            'contact_id' => $duplicate->id,
            'whatsapp_session_id' => $session->id,
            'is_group' => false,
        ]);

        $this->actingAs($admin)
            ->get(route('clients.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Clients/Index')
                ->has('clients.data', 1)
                ->where('clients.data.0.name', 'Merged Client'));
    }
}
