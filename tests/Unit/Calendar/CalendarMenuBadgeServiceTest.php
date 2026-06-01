<?php

declare(strict_types=1);

namespace Tests\Unit\Calendar;

use App\Models\CalendarEvent;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\Calendar\CalendarMenuBadgeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class CalendarMenuBadgeServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_counts_today_upcoming_events_visible_to_user(): void
    {
        SystemSetting::setValue('module_calendar', 'on');

        Role::findOrCreate('administrator', 'web');
        $user = User::factory()->create();
        $user->assignRole('administrator');

        CalendarEvent::query()->create([
            'user_id' => $user->id,
            'title' => 'Past today',
            'starts_at' => now()->subHours(3),
            'ends_at' => now()->subHour(),
            'all_day' => false,
        ]);

        CalendarEvent::query()->create([
            'user_id' => $user->id,
            'title' => 'Upcoming today',
            'starts_at' => now()->addHour(),
            'ends_at' => now()->addHours(2),
            'all_day' => false,
        ]);

        CalendarEvent::query()->create([
            'user_id' => $user->id,
            'title' => 'Tomorrow',
            'starts_at' => now()->addDay()->setHour(10),
            'ends_at' => now()->addDay()->setHour(11),
            'all_day' => false,
        ]);

        $count = app(CalendarMenuBadgeService::class)->countFor($user);

        $this->assertSame(1, $count);
    }

    public function test_returns_zero_when_calendar_module_disabled(): void
    {
        SystemSetting::setValue('module_calendar', 'off');

        Role::findOrCreate('administrator', 'web');
        $user = User::factory()->create();
        $user->assignRole('administrator');

        CalendarEvent::query()->create([
            'user_id' => $user->id,
            'title' => 'Upcoming today',
            'starts_at' => now()->addHour(),
            'ends_at' => now()->addHours(2),
            'all_day' => false,
        ]);

        $this->assertSame(0, app(CalendarMenuBadgeService::class)->countFor($user));
    }
}
