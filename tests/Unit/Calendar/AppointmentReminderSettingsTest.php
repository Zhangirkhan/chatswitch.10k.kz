<?php

declare(strict_types=1);

namespace Tests\Unit\Calendar;

use App\Services\Calendar\AppointmentReminderSettings;
use Carbon\Carbon;
use Tests\TestCase;

final class AppointmentReminderSettingsTest extends TestCase
{
    private AppointmentReminderSettings $settings;

    protected function setUp(): void
    {
        parent::setUp();
        $this->settings = app(AppointmentReminderSettings::class);
    }

    public function test_resolve_schedulable_lead_falls_back_to_minimum_when_preferred_is_past(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-10 12:06:00', config('app.timezone')));

        $startsAt = Carbon::parse('2026-06-10 14:00:00', config('app.timezone'));
        $lead = $this->settings->resolveSchedulableLeadMinutes($startsAt, 120);

        $this->assertSame(AppointmentReminderSettings::MIN_LEAD_TIME_MINUTES, $lead);

        Carbon::setTestNow();
    }

    public function test_client_suffix_omits_promise_when_no_schedulable_reminder(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-10 13:58:00', config('app.timezone')));

        $startsAt = Carbon::parse('2026-06-10 14:00:00', config('app.timezone'));
        $suffix = $this->settings->clientReminderSuffixForBookingConfirmation($startsAt, 120);

        $this->assertSame('', $suffix);

        Carbon::setTestNow();
    }

    public function test_client_suffix_uses_schedulable_lead_not_unavailable_preferred(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-10 12:06:00', config('app.timezone')));

        $startsAt = Carbon::parse('2026-06-10 14:00:00', config('app.timezone'));
        $suffix = $this->settings->clientReminderSuffixForBookingConfirmation($startsAt, 120);

        $this->assertStringContainsString('5 мин', $suffix);
        $this->assertStringNotContainsString('2 час', $suffix);

        Carbon::setTestNow();
    }
}
