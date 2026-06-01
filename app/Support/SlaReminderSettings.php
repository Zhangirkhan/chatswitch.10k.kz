<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\SystemSetting;

final readonly class SlaReminderSettings
{
    public const ENABLED_KEY = 'chats.sla_reminders.enabled';

    public const MINUTES_KEY = 'chats.sla_reminder_minutes';

    public const DEFAULT_ENABLED = true;

    public const DEFAULT_MINUTES = 15;

    public const MIN_MINUTES = 5;

    public const MAX_MINUTES = 120;

    /**
     * @return array<string, string>
     */
    public static function defaults(): array
    {
        return [
            self::ENABLED_KEY => self::DEFAULT_ENABLED ? 'on' : 'off',
            self::MINUTES_KEY => (string) self::DEFAULT_MINUTES,
        ];
    }

    public function enabled(?int $companyId = null): bool
    {
        return SystemSetting::getValue(self::ENABLED_KEY, self::DEFAULT_ENABLED ? 'on' : 'off', $companyId) !== 'off';
    }

    public function waitMinutes(?int $companyId = null): int
    {
        $raw = SystemSetting::getValue(self::MINUTES_KEY, (string) self::DEFAULT_MINUTES, $companyId);
        $minutes = is_numeric($raw) ? (int) $raw : self::DEFAULT_MINUTES;

        return min(self::MAX_MINUTES, max(self::MIN_MINUTES, $minutes));
    }
}
