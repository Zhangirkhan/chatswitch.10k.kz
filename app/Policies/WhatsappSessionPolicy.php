<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Models\WhatsappSession;

final class WhatsappSessionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole('administrator');
    }

    public function manage(User $user): bool
    {
        return $user->hasRole('administrator');
    }

    public function use(User $user, WhatsappSession $session): bool
    {
        if ($user->hasRole('administrator')) {
            return true;
        }

        return $user->whatsappSessions()
            ->where('whatsapp_sessions.id', $session->id)
            ->exists();
    }
}
