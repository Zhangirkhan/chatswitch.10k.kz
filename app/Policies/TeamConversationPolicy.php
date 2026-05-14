<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\TeamConversation;
use App\Models\User;

final class TeamConversationPolicy
{
    public function view(User $user, TeamConversation $teamConversation): bool
    {
        return $teamConversation->participants()->where('users.id', $user->id)->exists();
    }
}
