<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;

final class TenantAuthorizer
{
    public static function can(User $user, string $permission): bool
    {
        return $user->can($permission);
    }

    public static function canAny(User $user, array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($user->can($permission)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Переходный helper: permission или legacy-роль.
     */
    public static function hasLegacyOrPermission(User $user, string $legacyRole, string $permission): bool
    {
        if ($user->can($permission)) {
            return true;
        }

        return $user->hasRole($legacyRole);
    }

    public static function hasLegacyOrAnyPermission(User $user, array $legacyRoles, array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($user->can($permission)) {
                return true;
            }
        }

        return $user->hasAnyRole($legacyRoles);
    }
}
