<?php

declare(strict_types=1);

namespace Tests\Unit\Contact;

use App\Models\Chat;
use App\Models\Contact;
use App\Models\Funnel;
use App\Models\FunnelStage;
use App\Models\User;
use App\Models\WhatsappSession;
use App\Services\Contact\ClientProfileAssembler;
use App\Support\TenantCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class ClientProfileAssemblerTest extends TestCase
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

    public function test_assembler_maps_deal_stage_and_channels(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('administrator');

        $contact = Contact::factory()->create([
            'name' => 'Дана',
            'phone_number' => '77009998877',
        ]);

        $funnel = Funnel::query()->create([
            'company_id' => TenantCompany::id(),
            'name' => 'Продажи',
            'description' => null,
            'color' => '#25d366',
            'is_active' => true,
            'position' => 0,
        ]);
        $stage = FunnelStage::query()->create([
            'funnel_id' => $funnel->id,
            'name' => 'Переговоры',
            'color' => '#3b82f6',
            'position' => 0,
            'is_active' => true,
        ]);

        $session = WhatsappSession::factory()->create();
        Chat::factory()->create([
            'company_id' => TenantCompany::id(),
            'contact_id' => $contact->id,
            'whatsapp_session_id' => $session->id,
            'funnel_id' => $funnel->id,
            'funnel_stage_id' => $stage->id,
            'is_group' => false,
        ]);

        $profile = app(ClientProfileAssembler::class)->build($admin, $contact);

        $basic = collect($profile['sections'])->firstWhere('key', 'basic');
        $this->assertNotNull($basic);
        $this->assertTrue(
            collect($basic['fields'] ?? [])
                ->contains(fn (array $field): bool => str_contains((string) ($field['value'] ?? ''), 'Переговоры')),
        );

        $contactsSection = collect($profile['sections'])->firstWhere('key', 'contacts');
        $this->assertNotNull($contactsSection);
        $this->assertTrue(
            collect($contactsSection['fields'] ?? [])
                ->contains(fn (array $field): bool => ($field['label'] ?? '') === 'Телефон'),
        );
    }
}
