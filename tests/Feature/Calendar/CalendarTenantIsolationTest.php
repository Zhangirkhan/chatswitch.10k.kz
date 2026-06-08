<?php

declare(strict_types=1);

namespace Tests\Feature\Calendar;

use App\Models\CalendarEvent;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\Calendar\VisibleCalendarEventsQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class CalendarTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        SystemSetting::setValue('module_calendar', 'on');
        Role::findOrCreate('administrator', 'web');
    }

    public function test_administrator_sees_only_current_tenant_calendar_events(): void
    {
        $companyA = $this->createTenantCompany(['slug' => 'calendar-tenant-a', 'name' => 'Tenant A']);
        $adminA = User::factory()->create(['company_id' => $companyA->id]);
        $adminA->assignRole('administrator');

        CalendarEvent::query()->create([
            'user_id' => $adminA->id,
            'title' => 'Tenant A event',
            'starts_at' => now()->addHour(),
            'ends_at' => now()->addHours(2),
        ]);

        $companyB = $this->createTenantCompany(['slug' => 'calendar-tenant-b', 'name' => 'Tenant B']);
        $adminB = User::factory()->create(['company_id' => $companyB->id]);
        $adminB->assignRole('administrator');

        CalendarEvent::query()->create([
            'user_id' => $adminB->id,
            'title' => 'Tenant B event',
            'starts_at' => now()->addHour(),
            'ends_at' => now()->addHours(2),
        ]);

        $this->switchTenant($companyA);

        $titles = VisibleCalendarEventsQuery::forUser($adminA)
            ->orderBy('id')
            ->pluck('title')
            ->all();

        $this->assertSame(['Tenant A event'], $titles);
    }

    public function test_calendar_events_endpoint_returns_only_current_tenant_events(): void
    {
        $companyA = $this->createTenantCompany(['slug' => 'calendar-api-a', 'name' => 'Tenant A']);
        $adminA = User::factory()->create(['company_id' => $companyA->id]);
        $adminA->assignRole('administrator');

        CalendarEvent::query()->create([
            'user_id' => $adminA->id,
            'title' => 'Visible in A',
            'starts_at' => now()->addHour(),
            'ends_at' => now()->addHours(2),
        ]);

        $companyB = $this->createTenantCompany(['slug' => 'calendar-api-b', 'name' => 'Tenant B']);
        $adminB = User::factory()->create(['company_id' => $companyB->id]);
        $adminB->assignRole('administrator');

        CalendarEvent::query()->create([
            'user_id' => $adminB->id,
            'title' => 'Hidden from A',
            'starts_at' => now()->addHour(),
            'ends_at' => now()->addHours(2),
        ]);

        $this->switchTenant($companyA);

        $response = $this->actingAs($adminA)->getJson(route('calendar.events', [
            'start' => now()->startOfMonth()->toDateString(),
            'end' => now()->endOfMonth()->toDateString(),
        ]));

        $response->assertOk();
        $titles = collect($response->json())->pluck('title')->all();
        $this->assertContains('Visible in A', $titles);
        $this->assertNotContains('Hidden from A', $titles);
    }
}
