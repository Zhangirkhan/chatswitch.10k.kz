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

        // Множественное членство в отделах: пользователь может состоять в нескольких,
        // и доступ к чату открывается, если ХОТЬ ОДИН его отдел прикреплён к чату.
        $userDeptIds = $user->departmentIds();
        $userInChatDepartment = $userDeptIds !== []
            && $chat->departments()->whereIn('departments.id', $userDeptIds)->exists();

        if ($user->hasRole('manager')) {
            if ($userInChatDepartment) {
                return true;
            }

            if ($userDeptIds === []) {
                return false;
            }

            $departmentUserIds = User::query()
                ->whereHas('departments', static fn ($q) => $q->whereIn('departments.id', $userDeptIds))
                ->pluck('id');

            return $chat->assignments()
                ->whereIn('user_id', $departmentUserIds)
                ->exists();
        }

        if ($chat->assignments()->where('user_id', $user->id)->exists()) {
            return true;
        }

        if ($userInChatDepartment && ! $chat->assignments()->exists()) {
            return true;
        }

        return false;
    }

    public function manageFunnel(User $user, Chat $chat): bool
    {
        return $this->view($user, $chat);
    }

    public function sendMessage(User $user, Chat $chat): bool
    {
        return $this->view($user, $chat);
    }

    public function manageAi(User $user, Chat $chat): bool
    {
        if ($user->hasRole('administrator')) {
            return true;
        }

        return $chat->assignments()
            ->where('user_id', $user->id)
            ->exists();
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

    /**
     * Прикрепление отделов к чату: только администратор и руководитель.
     * Сотрудник видит в интерфейсе только свой отдел (без изменения).
     */
    public function syncDepartments(User $user, Chat $chat): bool
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
