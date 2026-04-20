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
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
        ];
    }

    private function archivedChatsCount(User $user): int
    {
        $query = Chat::query()->where('is_archived', true);

        if ($user->hasRole('administrator')) {
            return $query->count();
        }

        if ($user->hasRole('manager')) {
            $departmentUserIds = User::where('department_id', $user->department_id)->pluck('id');
            $query->whereHas('assignments', fn (Builder $q) => $q->whereIn('user_id', $departmentUserIds));

            return $query->count();
        }

        $query->whereHas('assignments', fn (Builder $q) => $q->where('user_id', $user->id));

        return $query->count();
    }
}
