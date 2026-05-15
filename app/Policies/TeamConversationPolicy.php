<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\TeamConversation;
use App\Models\User;

final class TeamConversationPolicy
{
    public function view(User $user, TeamConversation $teamConversation): bool
    {
        if ($user->hasRole('administrator') && $teamConversation->isDepartment()) {
            return true;
        }

        return $teamConversation->participants()->where('users.id', $user->id)->exists();
    }

    /**
     * Закрепить сообщение в чате отдела (общее для всех участников).
     */
    public function pinRoomMessage(User $user, TeamConversation $teamConversation): bool
    {
        if (! $teamConversation->isDepartment()) {
            return false;
        }

        if (! $teamConversation->participants()->where('users.id', $user->id)->exists()) {
            return false;
        }

        return $user->hasAnyRole(['administrator', 'manager']);
    }
}
