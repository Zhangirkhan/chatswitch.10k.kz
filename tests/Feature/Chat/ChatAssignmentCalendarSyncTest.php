<?php

declare(strict_types=1);

namespace Tests\Feature\Chat;

use App\Models\CalendarEvent;
use App\Models\Chat;
use App\Models\ChatAssignment;
use App\Models\Company;
use App\Models\User;
use App\Models\WhatsappSession;
use App\Services\Calendar\ChatAssignmentCalendarSyncService;
use App\Support\TenantCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class ChatAssignmentCalendarSyncTest extends TestCase
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

    public function test_assigning_master_updates_upcoming_calendar_event(): void
    {
        $company = Company::query()->findOrFail(TenantCompany::id());
        $session = WhatsappSession::factory()->create();
        $manager = User::factory()->create(['company_id' => $company->id]);
        $manager->assignRole('administrator');
        $master = User::factory()->create(['company_id' => $company->id, 'name' => 'Михаил']);
        $master->assignRole('employee');

        $chat = Chat::factory()->create([
            'company_id' => $company->id,
            'whatsapp_session_id' => $session->id,
        ]);

        $event = CalendarEvent::query()->create([
            'user_id' => $manager->id,
            'assignee_user_id' => $manager->id,
            'chat_id' => $chat->id,
            'title' => 'Замер',
            'starts_at' => now()->addDay()->setTime(10, 0),
            'ends_at' => now()->addDay()->setTime(11, 0),
            'source' => CalendarEvent::SOURCE_AI_AUTO,
        ]);

        $this->actingAs($manager)
            ->postJson(route('chats.assign', $chat), ['user_id' => $master->id])
            ->assertOk();

        $event->refresh();
        $this->assertSame($master->id, (int) $event->assignee_user_id);
        $this->assertDatabaseHas('chat_assignments', [
            'chat_id' => $chat->id,
            'user_id' => $master->id,
        ]);
    }

    public function test_ai_booking_assigns_master_to_chat_and_calendar(): void
    {
        $company = Company::query()->findOrFail(TenantCompany::id());
        $session = WhatsappSession::factory()->create();
        $master = User::factory()->create(['company_id' => $company->id]);
        $master->assignRole('employee');

        $chat = Chat::factory()->create([
            'company_id' => $company->id,
            'whatsapp_session_id' => $session->id,
        ]);

        ChatAssignment::query()->create([
            'chat_id' => $chat->id,
            'user_id' => $master->id,
            'assigned_by' => $master->id,
        ]);

        $sync = app(ChatAssignmentCalendarSyncService::class);
        $updated = $sync->syncUpcomingEventsForChat($chat, $master->id);

        $this->assertSame(0, $updated);

        CalendarEvent::query()->create([
            'user_id' => $master->id,
            'assignee_user_id' => $master->id,
            'chat_id' => $chat->id,
            'title' => 'Маникюр',
            'starts_at' => now()->addDays(2)->setTime(14, 0),
            'ends_at' => now()->addDays(2)->setTime(15, 0),
            'source' => CalendarEvent::SOURCE_AI_AUTO,
        ]);

        $otherMaster = User::factory()->create(['company_id' => $company->id]);
        $updated = $sync->syncUpcomingEventsForChat($chat, $otherMaster->id);

        $this->assertSame(1, $updated);
        $this->assertSame($otherMaster->id, (int) CalendarEvent::query()->where('chat_id', $chat->id)->value('assignee_user_id'));
    }
}
