<?php

declare(strict_types=1);

namespace Tests\Feature\Contact;

use App\Models\Chat;
use App\Models\Contact;
use App\Models\ContactFieldDefinition;
use App\Models\User;
use App\Models\WhatsappSession;
use App\Support\ContactFieldType;
use App\Support\TenantCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class ContactFieldPhotoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['administrator', 'manager', 'employee'] as $role) {
            Role::findOrCreate($role);
        }

        TenantCompany::ensureExists();
        Storage::fake('public');
    }

    public function test_profile_includes_photo_field_with_whatsapp_avatar(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('administrator');

        $contact = Contact::factory()->create([
            'profile_picture_url' => 'https://example.com/avatar.jpg',
        ]);
        $session = WhatsappSession::factory()->create();
        Chat::factory()->create([
            'contact_id' => $contact->id,
            'whatsapp_session_id' => $session->id,
            'is_group' => false,
        ]);

        $response = $this->actingAs($admin)->getJson(route('clients.profile', $contact));
        $response->assertOk();

        $basic = collect($response->json('profile.sections'))->firstWhere('key', 'basic');
        $photo = collect($basic['fields'] ?? [])->firstWhere('code', 'photo');

        $this->assertNotNull($photo);
        $this->assertSame('https://example.com/avatar.jpg', $photo['preview_url'] ?? null);
    }

    public function test_admin_can_upload_photo_and_sync_profile_picture(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('administrator');

        $contact = Contact::factory()->create(['profile_picture_url' => null]);
        $session = WhatsappSession::factory()->create();
        Chat::factory()->create([
            'contact_id' => $contact->id,
            'whatsapp_session_id' => $session->id,
            'is_group' => false,
        ]);

        app(\App\Services\Contact\ContactFieldDefinitionService::class)->ensureSystemFields(TenantCompany::id());
        $definition = ContactFieldDefinition::query()
            ->where('company_id', TenantCompany::id())
            ->where('code', 'photo')
            ->firstOrFail();

        $file = UploadedFile::fake()->image('client.jpg');

        $response = $this->actingAs($admin)->postJson(
            route('contacts.fields.upload', ['contact' => $contact->id, 'fieldDefinition' => $definition->id]),
            ['file' => $file],
        );

        $response->assertOk();
        $contact->refresh();

        $this->assertNotNull($contact->profile_picture_url);
        $this->assertStringContainsString('/storage/', (string) $contact->profile_picture_url);

        $basic = collect($response->json('profile.sections'))->firstWhere('key', 'basic');
        $photo = collect($basic['fields'] ?? [])->firstWhere('code', 'photo');
        $this->assertNotNull($photo['preview_url'] ?? null);
    }

    public function test_admin_can_save_email_system_field(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('administrator');

        $contact = Contact::factory()->create();
        $session = WhatsappSession::factory()->create();
        Chat::factory()->create([
            'contact_id' => $contact->id,
            'whatsapp_session_id' => $session->id,
            'is_group' => false,
        ]);

        app(\App\Services\Contact\ContactFieldDefinitionService::class)->ensureSystemFields(TenantCompany::id());
        $definition = ContactFieldDefinition::query()
            ->where('company_id', TenantCompany::id())
            ->where('code', 'email')
            ->firstOrFail();

        $this->actingAs($admin)->patchJson(route('contacts.fields.update', $contact), [
            'fields' => [
                ['field_id' => $definition->id, 'value' => 'client@example.com'],
            ],
        ])->assertOk();

        $profile = $this->actingAs($admin)->getJson(route('clients.profile', $contact));
        $contactsSection = collect($profile->json('profile.sections'))->firstWhere('key', 'contacts');
        $this->assertTrue(
            collect($contactsSection['fields'] ?? [])
                ->contains(fn (array $row): bool => ($row['code'] ?? '') === 'email' && str_contains((string) ($row['value'] ?? ''), 'client@example.com')),
        );
    }
}
