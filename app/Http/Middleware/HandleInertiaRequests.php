<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Chat;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Middleware;

final class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /** @return array<string, mixed> */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user ? array_merge(
                    $user->toArray(),
                    [
                        'roles' => $user->getRoleNames(),
                        'department' => $user->department,
                    ],
                ) : null,
            ],
            'archivedCount' => fn () => $user ? $this->archivedChatsCount($user) : 0,
            'unreadChatsCount' => fn () => $user ? $this->unreadChatsCount($user) : 0,
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
        ];
    }

    private function archivedChatsCount(User $user): int
    {
        return $this->applyAccessScope(
            Chat::query()->where('is_archived', true),
            $user,
        )->count();
    }

    private function unreadChatsCount(User $user): int
    {
        return $this->applyAccessScope(
            Chat::query()->where('is_archived', false)->where('unread_count', '>', 0),
            $user,
        )->count();
    }

    /**
     * @param  Builder<Chat>  $query
     * @return Builder<Chat>
     */
    private function applyAccessScope(Builder $query, User $user): Builder
    {
        if ($user->hasRole('administrator')) {
            return $query;
        }

        if ($user->hasRole('manager')) {
            $departmentUserIds = User::where('department_id', $user->department_id)->pluck('id');
            $query->whereHas('assignments', fn (Builder $q) => $q->whereIn('user_id', $departmentUserIds));

            return $query;
        }

        $query->whereHas('assignments', fn (Builder $q) => $q->where('user_id', $user->id));

        return $query;
    }
}
