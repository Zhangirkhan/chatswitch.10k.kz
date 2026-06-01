<?php

declare(strict_types=1);

namespace Tests\Feature\Contact;

use App\Models\Chat;
use App\Models\Contact;
use App\Models\User;
use App\Models\WhatsappSession;
use App\Support\TenantCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class ContactFieldDefinitionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['administrator', 'manager', 'employee'] as $role) {
            Role::findOrCreate($role);
        }

        TenantCompany::ensureExists();
    }

    public function test_admin_can_create_custom_field_and_toggle_visibility(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('administrator');

        $create = $this->actingAs($admin)->postJson(route('settings.contact-fields.store'), [
            'label' => 'UTM-метка',
            'type' => 'string',
            'section' => 'contacts',
        ]);

        $create->assertCreated();
        $fieldId = (int) $create->json('field.id');
        $this->assertGreaterThan(0, $fieldId);

        $list = $this->actingAs($admin)->getJson(route('settings.contact-fields.list'));
        $list->assertOk();
        $this->assertTrue(
            collect($list->json('fields'))
                ->contains(fn (array $row): bool => ($row['label'] ?? '') === 'UTM-метка'),
        );

        $this->actingAs($admin)->putJson(route('settings.contact-fields.visibility'), [
            'visibility' => [
                ['id' => $fieldId, 'is_visible' => false],
            ],
        ])->assertOk();

        $contact = Contact::factory()->create();
        $session = WhatsappSession::factory()->create();
        Chat::factory()->create([
            'contact_id' => $contact->id,
            'whatsapp_session_id' => $session->id,
            'is_group' => false,
        ]);

        $profile = $this->actingAs($admin)->getJson(route('clients.profile', $contact));
        $profile->assertOk();

        $contactsSection = collect($profile->json('profile.sections'))
            ->firstWhere('key', 'contacts');

        $this->assertIsArray($contactsSection);
        $this->assertFalse(
            collect($contactsSection['fields'] ?? [])
                ->contains(fn (array $row): bool => ($row['label'] ?? '') === 'UTM-метка'),
        );
    }

    public function test_admin_can_save_custom_field_value_on_contact(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('administrator');

        $create = $this->actingAs($admin)->postJson(route('settings.contact-fields.store'), [
            'label' => 'Источник',
            'type' => 'string',
            'section' => 'contacts',
        ]);

        $fieldId = (int) $create->json('field.id');
        $contact = Contact::factory()->create();
        $session = WhatsappSession::factory()->create();
        Chat::factory()->create([
            'contact_id' => $contact->id,
            'whatsapp_session_id' => $session->id,
            'is_group' => false,
        ]);

        $update = $this->actingAs($admin)->patchJson(route('contacts.fields.update', $contact), [
            'fields' => [
                ['field_id' => $fieldId, 'value' => 'Instagram'],
            ],
        ]);

        $update->assertOk();

        $fields = collect($update->json('profile.sections'))
            ->firstWhere('key', 'contacts')['fields'] ?? [];

        $this->assertTrue(
            collect($fields)->contains(
                fn (array $row): bool => ($row['label'] ?? '') === 'Источник' && str_contains((string) ($row['value'] ?? ''), 'Instagram'),
            ),
        );
    }
}
