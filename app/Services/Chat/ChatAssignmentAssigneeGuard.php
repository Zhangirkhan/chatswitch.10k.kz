<?php

declare(strict_types=1);

namespace App\Services\Chat;

use App\Models\Chat;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

final class ChatAssignmentAssigneeGuard
{
    /**
     * @return list<int>
     */
    public function allowedUserIds(User $actor, Chat $chat): array
    {
        if ($actor->hasRole('administrator')) {
            return User::query()
                ->where('is_active', true)
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->values()
                ->all();
        }

        if ($actor->hasRole('manager')) {
            $departmentIds = $actor->departmentIds();
            if ($departmentIds === []) {
                return [(int) $actor->id];
            }

            return User::query()
                ->where('is_active', true)
                ->where(function (Builder $query) use ($actor, $departmentIds): void {
                    $query->whereKey($actor->id)
                        ->orWhereHas('departments', static fn (Builder $departments) => $departments->whereIn('departments.id', $departmentIds));
                })
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->values()
                ->all();
        }

        return [];
    }

    /**
     * @param  list<int>  $userIds
     */
    public function assertAssignable(User $actor, Chat $chat, array $userIds): void
    {
        if ($userIds === []) {
            return;
        }

        $allowed = $this->allowedUserIds($actor, $chat);

        foreach ($userIds as $userId) {
            if (! in_array((int) $userId, $allowed, true)) {
                abort(422, 'Нельзя назначить выбранного сотрудника.');
            }
        }
    }
}
