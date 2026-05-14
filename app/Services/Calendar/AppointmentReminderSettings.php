<?php

declare(strict_types=1);

namespace App\Services\Calendar;

use App\Models\SystemSetting;

final readonly class AppointmentReminderSettings
{
    public const ENABLED_KEY = 'appointment_reminders.enabled';

    public const LEAD_TIME_MINUTES_KEY = 'appointment_reminders.lead_time_minutes';

    public const DEFAULT_ENABLED = true;

    public const DEFAULT_LEAD_TIME_MINUTES = 60;

    public const MIN_LEAD_TIME_MINUTES = 5;

    public const MAX_LEAD_TIME_MINUTES = 10080;

    /**
     * @return array<string, string>
     */
    public static function defaults(): array
    {
        return [
            self::ENABLED_KEY => self::DEFAULT_ENABLED ? 'on' : 'off',
            self::LEAD_TIME_MINUTES_KEY => (string) self::DEFAULT_LEAD_TIME_MINUTES,
        ];
    }

    public function enabled(): bool
    {
        return SystemSetting::getValue(self::ENABLED_KEY, self::DEFAULT_ENABLED ? 'on' : 'off') !== 'off';
    }

    public function leadTimeMinutes(): int
    {
        $raw = SystemSetting::getValue(self::LEAD_TIME_MINUTES_KEY, (string) self::DEFAULT_LEAD_TIME_MINUTES);
        $minutes = is_numeric($raw) ? (int) $raw : self::DEFAULT_LEAD_TIME_MINUTES;

        return min(self::MAX_LEAD_TIME_MINUTES, max(self::MIN_LEAD_TIME_MINUTES, $minutes));
    }

    /**
     * Фраза для исходящего подтверждения записи клиенту (после даты/времени услуги).
     */
    public function clientReminderSuffixForBookingConfirmation(): string
    {
        if (! $this->enabled()) {
            return '';
        }

        $m = $this->leadTimeMinutes();
        if ($m === 60) {
            return ' Напомним за час до визита.';
        }

        if ($m % 60 === 0) {
            $h = intdiv($m, 60);

            return ' Напомним за '.$h.' '.$this->hoursWordRu($h).' до визита.';
        }

        return ' Напомним за '.$m.' мин. до визита.';
    }

    private function hoursWordRu(int $h): string
    {
        $mod10 = $h % 10;
        $mod100 = $h % 100;

        if ($mod10 === 1 && $mod100 !== 11) {
            return 'час';
        }

        if ($mod10 >= 2 && $mod10 <= 4 && ($mod100 < 12 || $mod100 > 14)) {
            return 'часа';
        }

        return 'часов';
    }
}
