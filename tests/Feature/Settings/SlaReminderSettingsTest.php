<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Models\SystemSetting;
use App\Support\SlaReminderSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SlaReminderSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_wait_minutes_reads_from_system_setting(): void
    {
        SystemSetting::setValue(SlaReminderSettings::MINUTES_KEY, '25');
        SystemSetting::setValue(SlaReminderSettings::ENABLED_KEY, 'on');

        $settings = new SlaReminderSettings;

        $this->assertSame(25, $settings->waitMinutes());
        $this->assertTrue($settings->enabled());
    }

    public function test_disabled_when_setting_off(): void
    {
        SystemSetting::setValue(SlaReminderSettings::ENABLED_KEY, 'off');

        $this->assertFalse((new SlaReminderSettings)->enabled());
    }
}
