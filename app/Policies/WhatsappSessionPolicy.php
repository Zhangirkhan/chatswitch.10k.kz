<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Models\WhatsappSession;
use App\Support\TenantAuthorizer;

final class WhatsappSessionPolicy
{
    public function viewAny(User $user): bool
    {
        return TenantAuthorizer::hasLegacyOrAnyPermission($user, ['administrator'], ['whatsapp.manage', 'settings.manage']);
    }

    public function manage(User $user): bool
    {
        return TenantAuthorizer::hasLegacyOrPermission($user, 'administrator', 'whatsapp.manage');
    }

    public function use(User $user, WhatsappSession $session): bool
    {
        if (TenantAuthorizer::hasLegacyOrPermission($user, 'administrator', 'whatsapp.manage')) {
            return true;
        }

        if (! TenantAuthorizer::hasLegacyOrAnyPermission($user, ['manager', 'employee'], ['whatsapp.use'])) {
            return false;
        }

        return $user->whatsappSessions()
            ->where('whatsapp_sessions.id', $session->id)
            ->exists();
    }
}
