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
            'chats' => self::hasChatAccess($user),
            'clients' => self::canAccess($user, ['module_clients'], ['contacts.view', 'contacts.manage'], ['administrator', 'manager', 'employee']),
            'broadcasts' => self::canAccess($user, ['module_broadcasts'], ['broadcasts.manage'], ['administrator', 'manager']),
            'ai_chat' => self::canAccess($user, ['module_ai_chat'], ['chats.send'], ['administrator', 'manager', 'employee']),
            'analytics' => self::canAccess($user, ['module_analytics', 'module_funnels'], ['analytics.view', 'funnels.view'], ['administrator', 'manager', 'employee'], anyModule: true),
            'calendar' => self::canAccess($user, ['module_calendar'], ['calendar.manage'], ['administrator', 'manager', 'employee']),
            'funnels' => self::canAccess($user, ['module_funnels'], ['funnels.view', 'funnels.manage'], ['administrator', 'manager', 'employee']),
            'organization' => self::canAccess($user, ['module_tasks'], ['team_chat.use'], ['administrator', 'manager', 'employee']),
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

    private static function hasChatAccess(User $user): bool
    {
        return TenantAuthorizer::hasLegacyOrAnyPermission(
            $user,
            ['administrator', 'manager', 'employee'],
            ['chats.view_all', 'chats.view_department', 'chats.view_assigned', 'chats.send'],
        );
    }

    /**
     * @param  list<string>  $moduleKeys
     * @param  list<string>  $permissions
     * @param  list<string>  $legacyRoles
     */
    private static function canAccess(
        User $user,
        array $moduleKeys,
        array $permissions,
        array $legacyRoles,
        bool $anyModule = false,
    ): bool {
        if (! TenantAuthorizer::hasLegacyOrAnyPermission($user, $legacyRoles, $permissions)) {
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
