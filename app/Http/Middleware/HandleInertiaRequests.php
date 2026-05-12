<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Chat;
use App\Models\DepartmentPost;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\WhatsappSession;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Middleware;

final class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    /**
     * Inertia default checks config('app.asset_url') first; then the version never
     * changes on deploy and the SPA keeps old JS. Prefer Vite / Mix manifest hash.
     */
    public function version(Request $request): ?string
    {
        $viteManifest = public_path('build/manifest.json');
        if (is_file($viteManifest)) {
            return hash_file('xxh128', $viteManifest);
        }

        $mixManifest = public_path('mix-manifest.json');
        if (is_file($mixManifest)) {
            return hash_file('xxh128', $mixManifest);
        }

        $assetUrl = config('app.asset_url');
        if (is_string($assetUrl) && $assetUrl !== '') {
            return hash('xxh128', $assetUrl);
        }

        return null;
    }

    /** @return array<string, mixed> */
    public function share(Request $request): array
    {
        $user = $request->user();
        if ($user !== null) {
            $user->loadMissing(['departments:id,name']);
        }

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user ? array_merge(
                    $user->toArray(),
                    [
                        'roles' => $user->getRoleNames(),
                        'department' => $user->department,
                        'departments' => $user->departments
                            ->map(fn ($d) => ['id' => $d->id, 'name' => $d->name])
                            ->values()
                            ->all(),
                    ],
                ) : null,
            ],
            'archivedCount' => fn () => $user ? $this->archivedChatsCount($user) : 0,
            'unreadChatsCount' => fn () => $user ? $this->unreadChatsCount($user) : 0,
            'orgOpenTasksCount' => fn () => $user ? $this->orgOpenTasksCount($user) : 0,
            'whatsappSessions' => fn () => $user ? $this->whatsappSessionsForUser($user) : [],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
            'modules' => fn () => [
                'calendar' => SystemSetting::getValue('module_calendar', 'on') === 'on',
            ],
        ];
    }

    private function orgOpenTasksCount(User $user): int
    {
        $query = DepartmentPost::query()
            ->whereIn('status', [DepartmentPost::STATUS_OPEN, DepartmentPost::STATUS_IN_PROGRESS]);

        if (! $user->hasRole('administrator')) {
            $deptIds = $user->departmentIds();
            if ($deptIds === []) {
                return 0;
            }
            $query->whereHas(
                'department',
                fn (Builder $q) => $q->whereIn('departments.id', $deptIds),
            );
        }

        return $query->count();
    }

    /** @return array<int, array<string, mixed>> */
    private function whatsappSessionsForUser(User $user): array
    {
        $query = WhatsappSession::query();

        if (! $user->hasRole('administrator')) {
            $sessionIds = $user->whatsappSessions()->pluck('whatsapp_sessions.id');
            $query->whereIn('id', $sessionIds);
        }

        return $query
            ->orderBy('display_name')
            ->get(['id', 'session_name', 'display_name', 'display_color', 'wa_name', 'phone_number', 'status'])
            ->toArray();
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

        $userDeptIds = $user->departmentIds();

        if ($user->hasRole('manager')) {
            $departmentUserIds = $userDeptIds === []
                ? collect()
                : User::query()
                    ->whereHas('departments', static fn (Builder $q) => $q->whereIn('departments.id', $userDeptIds))
                    ->pluck('id');

            $query->where(function (Builder $q) use ($departmentUserIds, $userDeptIds): void {
                $q->whereHas('assignments', fn (Builder $aq) => $aq->whereIn('user_id', $departmentUserIds));
                if ($userDeptIds !== []) {
                    $q->orWhereHas('departments', fn (Builder $dq) => $dq->whereIn('departments.id', $userDeptIds));
                }
            });

            return $query;
        }

        $query->where(function (Builder $q) use ($user, $userDeptIds): void {
            $q->whereHas('assignments', fn (Builder $aq) => $aq->where('user_id', $user->id));
            if ($userDeptIds !== []) {
                $q->orWhere(function (Builder $dq) use ($userDeptIds): void {
                    $dq->whereDoesntHave('assignments')
                        ->whereHas('departments', fn (Builder $ddq) => $ddq->whereIn('departments.id', $userDeptIds));
                });
            }
        });

        return $query;
    }
}
