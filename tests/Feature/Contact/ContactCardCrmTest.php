<?php

declare(strict_types=1);

namespace Tests\Feature\Contact;

use App\Models\CalendarEvent;
use App\Models\Chat;
use App\Models\ChatAssignment;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Department;
use App\Models\DepartmentPost;
use App\Models\Funnel;
use App\Models\FunnelStage;
use App\Models\User;
use App\Models\WhatsappSession;
use App\Support\TenantCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class ContactCardCrmTest extends TestCase
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

    public function test_contact_card_includes_crm_deal_events_and_tasks(): void
    {
        $company = Company::query()->findOrFail(TenantCompany::id());
        $admin = User::factory()->create(['company_id' => $company->id]);
        $admin->assignRole('administrator');

        $contact = Contact::factory()->create([
            'name' => 'Айдар',
            'phone_number' => '77001112233',
        ]);
        $contact->companies()->sync([
            $company->id => ['position' => 'Директор'],
        ]);

        $funnel = Funnel::query()->create([
            'company_id' => $company->id,
            'name' => 'Продажи',
            'description' => null,
            'color' => '#25d366',
            'is_active' => true,
            'position' => 0,
        ]);
        $stage = FunnelStage::query()->create([
            'funnel_id' => $funnel->id,
            'name' => 'КП отправлено',
            'color' => '#3b82f6',
            'position' => 0,
            'is_active' => true,
        ]);

        $session = WhatsappSession::factory()->create();
        $chat = Chat::factory()->create([
            'company_id' => $company->id,
            'contact_id' => $contact->id,
            'whatsapp_session_id' => $session->id,
            'funnel_id' => $funnel->id,
            'funnel_stage_id' => $stage->id,
            'ai_enabled' => true,
            'ai_mode' => 'auto',
            'ai_orchestrator_last_summary' => 'Клиент просит счёт до пятницы.',
            'chat_name' => 'Айдар',
            'is_group' => false,
        ]);

        ChatAssignment::query()->create([
            'chat_id' => $chat->id,
            'user_id' => $admin->id,
            'assigned_by' => $admin->id,
        ]);

        CalendarEvent::query()->create([
            'user_id' => $admin->id,
            'assignee_user_id' => $admin->id,
            'chat_id' => $chat->id,
            'contact_id' => $contact->id,
            'title' => 'Звонок клиенту',
            'starts_at' => now()->addHours(2),
            'ends_at' => now()->addHours(3),
            'source' => CalendarEvent::SOURCE_AI_AUTO,
        ]);

        $department = Department::query()->create([
            'name' => 'Продажи',
            'description' => null,
            'is_active' => true,
        ]);

        DepartmentPost::query()->create([
            'department_id' => $department->id,
            'author_id' => $admin->id,
            'title' => 'Проверить оплату',
            'body' => 'Связаться с клиентом.'."\n\nЧат: ".route('chats.show', $chat),
            'status' => DepartmentPost::STATUS_OPEN,
        ]);

        $response = $this->actingAs($admin, 'web')
            ->getJson(route('contacts.card', ['contact' => $contact->id, 'chat_id' => $chat->id]))
            ->assertOk();

        $response->assertJsonPath('crm.deal.chat_id', $chat->id);
        $response->assertJsonPath('crm.deal.funnel.name', 'Продажи');
        $response->assertJsonPath('crm.deal.stage.name', 'КП отправлено');
        $response->assertJsonPath('crm.companies.0.name', $company->name);
        $response->assertJsonPath('crm.companies.0.position', 'Директор');
        $response->assertJsonPath('crm.upcoming_events.0.title', 'Звонок клиенту');
        $response->assertJsonPath('crm.open_tasks.0.title', 'Проверить оплату');
        $response->assertJsonFragment(['label' => 'Контекст AI']);
    }
}
