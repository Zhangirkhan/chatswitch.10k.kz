<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Chat;
use App\Models\User;
use App\Support\TenantAuthorizer;

final class ChatPolicy
{
    public function view(User $user, Chat $chat): bool
    {
        if (TenantAuthorizer::hasLegacyOrPermission($user, 'administrator', 'chats.view_all')) {
            return true;
        }

        $userDeptIds = $user->departmentIds();
        $userInChatDepartment = $userDeptIds !== []
            && $chat->departments()->whereIn('departments.id', $userDeptIds)->exists();

        if (TenantAuthorizer::hasLegacyOrPermission($user, 'employee', 'chats.view_assigned')
            && $chat->assignments()->where('user_id', $user->id)->exists()) {
            return true;
        }

        if (TenantAuthorizer::hasLegacyOrAnyPermission($user, ['employee', 'manager'], ['chats.view_department'])
            && $userInChatDepartment
            && ! $chat->assignments()->exists()) {
            return true;
        }

        if (($user->hasRole('manager') || TenantAuthorizer::can($user, 'chats.view_department'))
            && ! TenantAuthorizer::hasLegacyOrPermission($user, 'employee', 'chats.view_assigned')
            && $userDeptIds !== []) {
            $departmentUserIds = User::query()
                ->whereHas('departments', static fn ($q) => $q->whereIn('departments.id', $userDeptIds))
                ->pluck('id');

            if ($chat->assignments()
                ->whereIn('user_id', $departmentUserIds)
                ->exists()) {
                return true;
            }
        }

        return false;
    }

    public function manageFunnel(User $user, Chat $chat): bool
    {
        return $this->view($user, $chat);
    }

    public function sendMessage(User $user, Chat $chat): bool
    {
        if (! TenantAuthorizer::hasLegacyOrAnyPermission($user, ['administrator', 'manager', 'employee'], ['chats.send'])) {
            return false;
        }

        return $this->view($user, $chat);
    }

    public function manageAi(User $user, Chat $chat): bool
    {
        if (TenantAuthorizer::hasLegacyOrPermission($user, 'administrator', 'chats.view_all')) {
            return true;
        }

        return $chat->assignments()
            ->where('user_id', $user->id)
            ->exists();
    }

    public function manage(User $user, Chat $chat): bool
    {
        if (TenantAuthorizer::hasLegacyOrPermission($user, 'administrator', 'chats.view_all')) {
            return true;
        }

        return $this->view($user, $chat);
    }

    public function assign(User $user, Chat $chat): bool
    {
        if (TenantAuthorizer::hasLegacyOrPermission($user, 'administrator', 'chats.assign')) {
            return true;
        }

        if (TenantAuthorizer::hasLegacyOrPermission($user, 'manager', 'chats.assign')) {
            return $this->view($user, $chat);
        }

        return false;
    }

    public function syncDepartments(User $user, Chat $chat): bool
    {
        if (TenantAuthorizer::hasLegacyOrPermission($user, 'administrator', 'chats.view_all')) {
            return true;
        }

        if (TenantAuthorizer::hasLegacyOrPermission($user, 'manager', 'chats.assign')) {
            return $this->view($user, $chat);
        }

        return false;
    }

    public function delete(User $user, Chat $chat): bool
    {
        return $this->manage($user, $chat);
    }
}
