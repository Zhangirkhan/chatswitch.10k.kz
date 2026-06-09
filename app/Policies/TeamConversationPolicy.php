<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\TeamConversation;
use App\Models\User;
use App\Support\TenantAuthorizer;

final class TeamConversationPolicy
{
    public function view(User $user, TeamConversation $teamConversation): bool
    {
        if (! TenantAuthorizer::hasLegacyOrAnyPermission($user, ['administrator', 'manager', 'employee'], ['team_chat.use'])) {
            return false;
        }

        if (TenantAuthorizer::hasLegacyOrPermission($user, 'administrator', 'settings.manage')
            && $teamConversation->isDepartment()) {
            return true;
        }

        return $teamConversation->participants()->where('users.id', $user->id)->exists();
    }

    public function pinRoomMessage(User $user, TeamConversation $teamConversation): bool
    {
        if (! TenantAuthorizer::hasLegacyOrAnyPermission($user, ['administrator', 'manager'], ['team_chat.pin'])) {
            return false;
        }

        if (! $teamConversation->isDepartment()) {
            return false;
        }

        return $teamConversation->participants()->where('users.id', $user->id)->exists();
    }
}
