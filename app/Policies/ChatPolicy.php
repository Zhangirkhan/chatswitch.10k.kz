<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Chat;
use App\Models\User;

final class ChatPolicy
{
    /**
     * Administrator always sees everything; manager — chats assigned to any
     * user in their department; employee — only chats assigned to them.
     */
    public function view(User $user, Chat $chat): bool
    {
        if ($user->hasRole('administrator')) {
            return true;
        }

        $userInChatDepartment = $user->department_id !== null
            && $chat->departments()->where('departments.id', $user->department_id)->exists();

        // Руководитель видит всё, что относится к его отделу: и чаты своих сотрудников,
        // и чаты с прикреплённым его отделом (независимо от того, кто назначен).
        if ($user->hasRole('manager')) {
            if ($userInChatDepartment) {
                return true;
            }

            if ($user->department_id !== null) {
                $departmentUserIds = User::query()
                    ->where('department_id', $user->department_id)
                    ->pluck('id');

                return $chat->assignments()
                    ->whereIn('user_id', $departmentUserIds)
                    ->exists();
            }

            return false;
        }

        // Рядовой сотрудник:
        //  1) если он лично назначен — видит (он ответственный);
        //  2) иначе — видит только чаты своего отдела без назначенных (общий пул);
        //  3) как только чат кто-то взял — из его списка исчезает.
        if ($chat->assignments()->where('user_id', $user->id)->exists()) {
            return true;
        }

        if ($userInChatDepartment && ! $chat->assignments()->exists()) {
            return true;
        }

        return false;
    }

    public function sendMessage(User $user, Chat $chat): bool
    {
        return $this->view($user, $chat);
    }

    public function manage(User $user, Chat $chat): bool
    {
        if ($user->hasRole('administrator')) {
            return true;
        }

        return $this->view($user, $chat);
    }

    public function assign(User $user, Chat $chat): bool
    {
        if ($user->hasRole('administrator')) {
            return true;
        }

        if ($user->hasRole('manager')) {
            return $this->view($user, $chat);
        }

        return false;
    }

    public function delete(User $user, Chat $chat): bool
    {
        return $this->manage($user, $chat);
    }
}
