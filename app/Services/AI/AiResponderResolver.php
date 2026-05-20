<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\Chat;
use App\Models\Department;
use App\Models\FunnelAiScenario;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Подбирает сотрудника, от имени которого AI может отвечать клиенту,
 * даже если на чат ещё никто не назначен вручную.
 */
final class AiResponderResolver
{
    public function forChat(Chat $chat, ?FunnelAiScenario $scenario = null): ?User
    {
        $chat->loadMissing(['aiResponder', 'assignments.user', 'departments']);

        if ($scenario?->fallbackManager instanceof User && $scenario->fallbackManager->is_active) {
            return $scenario->fallbackManager;
        }

        if ($chat->aiResponder instanceof User && $chat->aiResponder->is_active) {
            return $chat->aiResponder;
        }

        $assigned = $chat->assignments
            ->first(fn ($assignment) => $assignment->user?->is_active)?->user;
        if ($assigned instanceof User) {
            return $assigned;
        }

        $fromDepartments = $this->firstActiveUserFromDepartments($chat->departments);
        if ($fromDepartments instanceof User) {
            return $fromDepartments;
        }

        if ($scenario?->fallbackDepartment instanceof Department) {
            $fromFallback = $this->firstActiveUserFromDepartments(collect([$scenario->fallbackDepartment]));
            if ($fromFallback instanceof User) {
                return $fromFallback;
            }
        }

        if ($chat->company_id !== null) {
            $manager = User::query()
                ->where('company_id', $chat->company_id)
                ->where('is_active', true)
                ->whereHas('roles', static fn ($q) => $q->whereIn('name', ['administrator', 'manager']))
                ->orderBy('name')
                ->first();
            if ($manager instanceof User) {
                return $manager;
            }

            $anyUser = User::query()
                ->where('company_id', $chat->company_id)
                ->where('is_active', true)
                ->orderBy('name')
                ->first();
            if ($anyUser instanceof User) {
                return $anyUser;
            }
        }

        $email = (string) config('chatswitch.system_user_email', 'system@chatswitch.internal');

        return User::query()->where('email', $email)->first();
    }

    /**
     * @param  Collection<int, Department>  $departments
     */
    private function firstActiveUserFromDepartments(Collection $departments): ?User
    {
        foreach ($departments->sortBy('id') as $department) {
            if (! $department->is_active) {
                continue;
            }

            $user = $department->users()
                ->where('is_active', true)
                ->orderBy('name')
                ->first();

            if ($user instanceof User) {
                return $user;
            }
        }

        return null;
    }
}
