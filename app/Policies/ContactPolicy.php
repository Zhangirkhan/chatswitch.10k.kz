<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Chat;
use App\Models\Contact;
use App\Models\User;
use App\Support\TenantAuthorizer;

final class ContactPolicy
{
    public function view(User $user, Contact $contact): bool
    {
        if (! TenantAuthorizer::hasLegacyOrAnyPermission($user, ['administrator', 'manager', 'employee'], ['contacts.view', 'contacts.manage'])) {
            return false;
        }

        return $this->hasVisibleChat($user, $contact);
    }

    public function update(User $user, Contact $contact): bool
    {
        if (! TenantAuthorizer::hasLegacyOrAnyPermission($user, ['administrator', 'manager'], ['contacts.manage'])) {
            return false;
        }

        return $this->hasVisibleChat($user, $contact);
    }

    public function syncCompanies(User $user, Contact $contact): bool
    {
        return $this->update($user, $contact);
    }

    public function clearData(User $user, Contact $contact): bool
    {
        return TenantAuthorizer::hasLegacyOrPermission($user, 'administrator', 'settings.manage')
            && $this->hasVisibleChat($user, $contact);
    }

    public function create(User $user): bool
    {
        return TenantAuthorizer::hasLegacyOrAnyPermission($user, ['administrator', 'manager'], ['contacts.manage']);
    }

    private function hasVisibleChat(User $user, Contact $contact): bool
    {
        $chats = Chat::query()
            ->where('contact_id', $contact->id)
            ->where('is_group', false)
            ->get(['id', 'company_id', 'whatsapp_session_id', 'is_archived']);

        foreach ($chats as $chat) {
            if ($user->can('view', $chat)) {
                return true;
            }
        }

        return false;
    }
}
