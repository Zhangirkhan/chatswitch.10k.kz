<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Chat;
use App\Models\Contact;
use App\Models\User;

final class ContactPolicy
{
    public function update(User $user, Contact $contact): bool
    {
        return $this->hasVisibleChat($user, $contact);
    }

    public function syncCompanies(User $user, Contact $contact): bool
    {
        return $this->hasVisibleChat($user, $contact);
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
