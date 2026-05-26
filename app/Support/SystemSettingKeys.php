<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\Calendar\AppointmentReminderSettings;

/**
 * Белый список ключей system_settings (защита от произвольной записи админом).
 */
final class SystemSettingKeys
{
    /** @return list<string> */
    public static function allowed(): array
    {
        return array_values(array_unique([
            'company_name',
            'auto_assign_chats',
            'max_sessions',
            'notification_sound',
            'analytics.sla_first_response_seconds',
            AppointmentReminderSettings::ENABLED_KEY,
            AppointmentReminderSettings::LEAD_TIME_MINUTES_KEY,
            SlaReminderSettings::ENABLED_KEY,
            SlaReminderSettings::MINUTES_KEY,
            QuickReactions::KEY,
        ]));
    }

    public static function isAllowed(string $key): bool
    {
        return in_array($key, self::allowed(), true);
    }
}
