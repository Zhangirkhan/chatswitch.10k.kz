<?php

declare(strict_types=1);

namespace Tests\Feature\Memory;

use App\Enums\EntityMemorySubjectType;
use App\Models\Chat;
use App\Models\ChatAssignment;
use App\Models\Contact;
use App\Models\EntityMemoryBackup;
use App\Models\User;
use App\Models\WhatsappSession;
use App\Services\Memory\EntityMemoryService;
use App\Support\TenantCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class EntityMemoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['administrator', 'manager', 'employee'] as $role) {
            Role::findOrCreate($role);
        }
        TenantCompany::ensureExists();
        Storage::fake('local');
        config(['entity-memory.disk' => 'local']);
    }

    public function test_update_creates_backup_and_syncs_memory_file(): void
    {
        $admin = User::factory()->create(['company_id' => TenantCompany::id()]);
        $admin->assignRole('administrator');

        $service = app(EntityMemoryService::class);
        $memory = $service->update(
            EntityMemorySubjectType::Tenant,
            TenantCompany::id(),
            "# Наша компания\n\nПервая версия.",
            $admin,
        );

        $service->update(
            EntityMemorySubjectType::Tenant,
            TenantCompany::id(),
            "# Наша компания\n\nВторая версия.",
            $admin,
        );

        $this->assertDatabaseHas('entity_memory_backups', [
            'entity_memory_id' => $memory->id,
        ]);

        $path = 'entity-memory/tenant/'.TenantCompany::id().'/memory.md';
        Storage::disk('local')->assertExists($path);
        $this->assertStringContainsString('Вторая версия', Storage::disk('local')->get($path));

        $this->assertGreaterThanOrEqual(1, EntityMemoryBackup::query()->where('entity_memory_id', $memory->id)->count());
    }

    public function test_employee_can_edit_contact_memory_when_assigned(): void
    {
        $employee = User::factory()->create(['company_id' => TenantCompany::id()]);
        $employee->assignRole('employee');

        $contact = Contact::factory()->create();
        $session = WhatsappSession::factory()->create();
        $chat = Chat::factory()->create([
            'whatsapp_session_id' => $session->id,
            'contact_id' => $contact->id,
            'company_id' => TenantCompany::id(),
        ]);
        ChatAssignment::query()->create([
            'chat_id' => $chat->id,
            'user_id' => $employee->id,
            'assigned_by' => $employee->id,
        ]);

        $this->actingAs($employee)->putJson(route('entity-memory.update', [
            'subjectType' => 'contact',
            'subjectId' => $contact->id,
        ]), [
            'content' => '# Клиент Иван\n\nЛюбит короткие ответы.',
        ])->assertOk();

        $this->assertDatabaseHas('entity_memories', [
            'subject_type' => EntityMemorySubjectType::Contact->value,
            'subject_id' => $contact->id,
        ]);
    }

    public function test_context_blocks_include_contact_memory_for_ai(): void
    {
        $responder = User::factory()->create(['company_id' => TenantCompany::id(), 'name' => 'Айгуль']);
        $responder->assignRole('administrator');
        $contact = Contact::factory()->create(['name' => 'Иван']);
        $session = WhatsappSession::factory()->create();
        $chat = Chat::factory()->create([
            'whatsapp_session_id' => $session->id,
            'contact_id' => $contact->id,
            'company_id' => TenantCompany::id(),
        ]);

        app(EntityMemoryService::class)->update(
            EntityMemorySubjectType::Contact,
            $contact->id,
            'Предпочитает доставку в субботу.',
            $responder,
        );

        $blocks = app(EntityMemoryService::class)->contextBlocksForChat($chat, $responder);
        $joined = implode("\n", $blocks);

        $this->assertStringContainsString('Предпочитает доставку в субботу', $joined);
        $this->assertStringContainsString('Иван', $joined);
    }
}
