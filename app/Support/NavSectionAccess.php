<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\SystemSetting;
use App\Models\User;

final class NavSectionAccess
{
    /**
     * @return array<string, bool>
     */
    public static function visibleFor(?User $user): array
    {
        if ($user === null) {
            return [
                'chats' => false,
                'clients' => false,
                'broadcasts' => false,
                'ai_chat' => false,
                'analytics' => false,
                'calendar' => false,
                'funnels' => false,
                'organization' => false,
            ];
        }

        return [
            'chats' => $user->hasAnyRole(['administrator', 'manager', 'employee']),
            'clients' => self::canAccess($user, ['module_clients'], ['administrator', 'manager', 'employee']),
            'broadcasts' => self::canAccess($user, ['module_broadcasts'], ['administrator', 'manager']),
            'ai_chat' => self::canAccess($user, ['module_ai_chat'], ['administrator', 'manager', 'employee']),
            'analytics' => self::canAccess($user, ['module_analytics', 'module_funnels'], ['administrator', 'manager', 'employee'], anyModule: true),
            'calendar' => self::canAccess($user, ['module_calendar'], ['administrator', 'manager', 'employee']),
            'funnels' => self::canAccess($user, ['module_funnels'], ['administrator', 'manager', 'employee']),
            'organization' => self::canAccess($user, ['module_tasks'], ['administrator', 'manager', 'employee']),
        ];
    }

    public static function assertModuleEnabled(string $moduleKey): void
    {
        abort_unless(
            CompanyModules::isModuleKey($moduleKey),
            500,
            'Неизвестный модуль.',
        );

        abort_unless(
            SystemSetting::getValue($moduleKey, 'on') === 'on',
            403,
            self::disabledMessage($moduleKey),
        );
    }

    /**
     * @param  list<string>  $moduleKeys
     * @param  list<string>  $roles
     */
    private static function canAccess(
        User $user,
        array $moduleKeys,
        array $roles,
        bool $anyModule = false,
    ): bool {
        if (! $user->hasAnyRole($roles)) {
            return false;
        }

        if ($moduleKeys === []) {
            return true;
        }

        if ($anyModule) {
            foreach ($moduleKeys as $moduleKey) {
                if (SystemSetting::getValue($moduleKey, 'on') === 'on') {
                    return true;
                }
            }

            return false;
        }

        return SystemSetting::getValue($moduleKeys[0], 'on') === 'on';
    }

    private static function disabledMessage(string $moduleKey): string
    {
        $label = CompanyModules::definitions()[$moduleKey]['label'] ?? 'Раздел';

        return "Модуль «{$label}» отключён администратором.";
    }
}
