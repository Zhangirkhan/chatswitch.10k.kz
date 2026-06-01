<?php

declare(strict_types=1);

namespace Tests\Feature\Contact;

use App\Enums\EntityMemorySubjectType;
use App\Models\Chat;
use App\Models\Contact;
use App\Models\ContactFieldDefinition;
use App\Models\ContactFieldValue;
use App\Models\EntityMemory;
use App\Models\User;
use App\Models\WhatsappSession;
use App\Support\TenantCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class ContactListFilterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('administrator');
        \App\Support\TenantCompany::ensureExists();
    }

    public function test_clients_index_filters_by_stored_city_field(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('administrator');

        $session = WhatsappSession::factory()->create();
        $companyId = TenantCompany::id();

        $definition = ContactFieldDefinition::query()->create([
            'company_id' => $companyId,
            'code' => 'city',
            'label' => 'Город',
            'type' => 'string',
            'section' => 'contacts',
            'group' => 'about',
            'is_system' => true,
            'is_visible' => true,
            'sort_order' => 81,
        ]);

        $match = Contact::factory()->create(['name' => 'Алматинец']);
        ContactFieldValue::query()->create([
            'company_id' => $companyId,
            'contact_id' => $match->id,
            'field_definition_id' => $definition->id,
            'value_text' => 'Алматы',
        ]);
        Chat::factory()->create([
            'contact_id' => $match->id,
            'whatsapp_session_id' => $session->id,
            'is_group' => false,
        ]);

        $other = Contact::factory()->create(['name' => 'Астанец']);
        ContactFieldValue::query()->create([
            'company_id' => $companyId,
            'contact_id' => $other->id,
            'field_definition_id' => $definition->id,
            'value_text' => 'Астана',
        ]);
        Chat::factory()->create([
            'contact_id' => $other->id,
            'whatsapp_session_id' => $session->id,
            'is_group' => false,
        ]);

        $this->actingAs($admin)
            ->get(route('clients.index', ['filters' => ['city' => 'Алматы']]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Clients/Index')
                ->has('clients.data', 1)
                ->where('clients.data.0.id', $match->id)
                ->where('filters.city', 'Алматы'));
    }

    public function test_filter_service_applies_memory_city(): void
    {
        \App\Support\TenantCompany::ensureExists();
        $companyId = TenantCompany::id();

        $match = Contact::factory()->create(['name' => 'Memory City']);
        EntityMemory::query()->create([
            'tenant_company_id' => $companyId,
            'subject_type' => EntityMemorySubjectType::Contact->value,
            'subject_id' => $match->id,
            'content' => "Город: Шымкент\nАдрес: ул. Тест",
            'content_hash' => hash('sha256', 'memory-city'),
        ]);

        $query = Contact::query();
        app(\App\Services\Contact\ContactListFilterService::class)
            ->apply($query, new \App\Support\ContactListFilters(['city' => 'Шымкент']));

        $this->assertSame(1, $query->count());
        $this->assertSame($match->id, (int) $query->value('id'));
    }

    public function test_clients_index_filters_by_memory_city(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('administrator');

        $session = WhatsappSession::factory()->create();
        $companyId = TenantCompany::id();

        $match = Contact::factory()->create(['name' => 'Memory City']);
        EntityMemory::query()->create([
            'tenant_company_id' => $companyId,
            'subject_type' => EntityMemorySubjectType::Contact->value,
            'subject_id' => $match->id,
            'content' => "Город: Шымкент\nАдрес: ул. Тест",
            'content_hash' => hash('sha256', 'x'),
        ]);
        Chat::factory()->create([
            'contact_id' => $match->id,
            'whatsapp_session_id' => $session->id,
            'is_group' => false,
        ]);

        $other = Contact::factory()->create(['name' => 'Other City']);
        Chat::factory()->create([
            'contact_id' => $other->id,
            'whatsapp_session_id' => $session->id,
            'is_group' => false,
        ]);

        $this->actingAs($admin)
            ->get(route('clients.index', ['filters' => ['city' => 'Шымкент']]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Clients/Index')
                ->has('clients.data', 1)
                ->where('clients.data.0.id', $match->id));
    }
}
