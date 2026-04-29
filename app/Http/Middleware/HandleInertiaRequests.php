<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Chat;
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
            'whatsappSessions' => fn () => $user ? $this->whatsappSessionsForUser($user) : [],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
        ];
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

        if ($user->hasRole('manager')) {
            $departmentUserIds = User::where('department_id', $user->department_id)->pluck('id');
            $query->where(function (Builder $q) use ($departmentUserIds, $user): void {
                $q->whereHas('assignments', fn (Builder $aq) => $aq->whereIn('user_id', $departmentUserIds));
                if ($user->department_id !== null) {
                    $q->orWhereHas('departments', fn (Builder $dq) => $dq->where('departments.id', $user->department_id));
                }
            });

            return $query;
        }

        $query->where(function (Builder $q) use ($user): void {
            $q->whereHas('assignments', fn (Builder $aq) => $aq->where('user_id', $user->id));
            if ($user->department_id !== null) {
                $q->orWhere(function (Builder $dq) use ($user): void {
                    $dq->whereDoesntHave('assignments')
                        ->whereHas('departments', fn (Builder $ddq) => $ddq->where('departments.id', $user->department_id));
                });
            }
        });

        return $query;
    }
}
