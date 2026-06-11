<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Chat;
use App\Models\DepartmentPost;
use App\Models\SystemSetting;
use App\Models\TenantSignupRequest;
use App\Models\UserFeedback;
use App\Enums\UserFeedbackStatus;
use App\Models\User;
use App\Models\WhatsappSession;
use App\Services\Calendar\CalendarMenuBadgeService;
use App\Services\DemoWhatsappSessionSimulator;
use App\Services\Security\RecaptchaVerifier;
use App\Services\PlatformBanner\PlatformBannerService;
use App\Services\SuperAdmin\TenantImpersonationService;
use App\Services\TeamDepartmentChatSyncService;
use App\Support\CompanyModules;
use App\Support\NavSectionAccess;
use App\Support\OrganizationDepartmentTasks;
use App\Support\QuickReactions;
use App\Support\TenantCompany;
use App\Support\TenantRoleLabels;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

        $isSuperAdminHost = app(TenantContext::class)->isAdminHost($request->getHost());

        return [
            ...parent::share($request),
            'tenantCompanyId' => fn () => app(TenantContext::class)->companyIdOrNull() ?? TenantCompany::id(),
            'tenantSlug' => fn () => app(TenantContext::class)->slug(),
            'appLocale' => fn () => (string) app()->getLocale(),
            'appVersion' => fn () => (string) config('app.version', '1.0.0'),
            'isSuperAdminHost' => fn () => $isSuperAdminHost,
            'impersonation' => fn () => $request->session()->get(TenantImpersonationService::SESSION_KEY),
            'platformBanners' => function () use ($request, $user, $isSuperAdminHost) {
                if ($user === null) {
                    return [];
                }

                $tenantContext = app(TenantContext::class);
                $companyId = $isSuperAdminHost ? null : $tenantContext->companyIdOrNull();

                return app(PlatformBannerService::class)->activeForWeb(
                    $companyId !== null ? (int) $companyId : null,
                    (string) app()->getLocale(),
                );
            },
            'auth' => [
                'user' => $user ? array_merge(
                    $user->toArray(),
                    [
                        'roles' => $user->getRoleNames()->values()->all(),
                        'can_pick_ai_responder' => $user->hasAnyRole(['administrator', 'manager']),
                        'department' => $user->department,
                        'departments' => $user->departments
                            ->map(fn ($d) => ['id' => $d->id, 'name' => $d->name])
                            ->values()
                            ->all(),
                    ],
                ) : null,
            ],
            'roleLabels' => fn () => $user ? TenantRoleLabels::all() : TenantRoleLabels::defaults(),
            'roleLabelsConfigured' => fn () => $user ? TenantRoleLabels::isConfigured() : false,
            'archivedCount' => fn () => $user ? $this->archivedChatsCount($user) : 0,
            'unreadChatsCount' => fn () => $user ? $this->unreadChatsCount($user) : 0,
            /** Непрочитанные только среди чатов, где пользователь в ответственных (для режима «Мои»). */
            'unreadChatsCountMine' => fn () => $user ? $this->unreadChatsCountMine($user) : 0,
            'orgOpenTasksCount' => fn () => ($user && ! $isSuperAdminHost) ? $this->orgOpenTasksCount($user) : 0,
            'teamChatUnreadCount' => fn () => ($user && ! $isSuperAdminHost) ? $this->teamChatUnreadTotal($user) : 0,
            'calendarBadgeCount' => fn () => ($user && ! $isSuperAdminHost) ? app(CalendarMenuBadgeService::class)->countFor($user) : 0,
            'whatsappSessions' => fn () => (
                $user && ! app(TenantContext::class)->isAdminHost($request->getHost())
            ) ? $this->whatsappSessionsForUser($user) : [],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
            'rootDomain' => fn () => (string) config('tenancy.root_domain', 'accel.kz'),
            'superAdminNav' => function () use ($request, $user) {
                if (! app(TenantContext::class)->isAdminHost($request->getHost())) {
                    return null;
                }

                $isSandbox = $user !== null
                    && $user->is_super_admin
                    && strtolower((string) ($user->super_admin_scope ?? 'global')) === 'sandbox';

                return [
                    'pending_signups' => $isSandbox
                        ? 0
                        : TenantSignupRequest::query()->where('status', 'pending')->count(),
                    'unread_feedback' => $isSandbox
                        ? 0
                        : UserFeedback::query()->where('status', UserFeedbackStatus::New)->count(),
                    'is_sandbox' => $isSandbox,
                ];
            },
            'isSandboxSuperAdmin' => fn () => $user !== null
                && $user->is_super_admin
                && strtolower((string) ($user->super_admin_scope ?? 'global')) === 'sandbox',
            'quickReactions' => fn () => QuickReactions::configured(),
            'recaptcha' => fn (): array => [
                'enabled' => RecaptchaVerifier::isEnabled(),
                'siteKey' => config('recaptcha.site_key'),
                'version' => config('recaptcha.version') === 'v2' ? 'v2' : 'v3',
            ],
            'modules' => fn () => [
                ...CompanyModules::inertiaFlags(),
                'org_tasks' => OrganizationDepartmentTasks::enabled(),
            ],
            'nav' => fn () => NavSectionAccess::visibleFor($user),
        ];
    }

    private function orgOpenTasksCount(User $user): int
    {
        if (! OrganizationDepartmentTasks::enabled()) {
            return 0;
        }

        $query = DepartmentPost::query()
            ->whereIn('status', [DepartmentPost::STATUS_OPEN, DepartmentPost::STATUS_IN_PROGRESS])
            ->whereHas('department', function (Builder $q) use ($user): void {
                $q->where('is_active', true);

                if (! $user->hasRole('administrator')) {
                    $deptIds = $user->departmentIds();
                    if ($deptIds === []) {
                        $q->whereRaw('1 = 0');

                        return;
                    }
                    $q->whereIn('departments.id', $deptIds);
                }
            });

        return $query->count();
    }

    private function teamChatUnreadTotal(User $user): int
    {
        if (SystemSetting::getValue('module_tasks', 'on') !== 'on') {
            return 0;
        }

        if ($user->hasRole('administrator')) {
            app(TeamDepartmentChatSyncService::class)->syncAdministratorToAllDepartmentChats($user);
        }

        $conversationQuery = $user->teamConversations();
        $context = app(TenantContext::class);
        if ($context->shouldApplyTenantScope()) {
            $conversationQuery->where(
                'team_conversations.company_id',
                $context->companyId(),
            );
        }

        $ids = $conversationQuery->pluck('team_conversations.id')->all();
        if ($ids === []) {
            return 0;
        }

        $query = DB::table('team_messages as m')
            ->join('team_conversation_user as cu', function ($join) use ($user): void {
                $join->on('cu.team_conversation_id', '=', 'm.team_conversation_id')
                    ->where('cu.user_id', '=', $user->id);
            })
            ->whereIn('m.team_conversation_id', $ids)
            ->where('m.sender_id', '!=', $user->id)
            ->whereNull('m.deleted_at')
            ->whereRaw('m.id > COALESCE(cu.last_read_message_id, 0)');

        if ($context->shouldApplyTenantScope()) {
            $query->join('team_conversations as tc', 'tc.id', '=', 'm.team_conversation_id')
                ->where('tc.company_id', $context->companyId());
        }

        return (int) $query->count();
    }

    /** @return array<int, array<string, mixed>> */
    private function whatsappSessionsForUser(User $user): array
    {
        $query = WhatsappSession::query();

        if (! $user->hasRole('administrator')) {
            $sessionIds = $user->whatsappSessions()->pluck('whatsapp_sessions.id');
            $query->whereIn('id', $sessionIds);
        }

        $sessions = $query
            ->orderBy('display_name')
            ->get(['id', 'session_name', 'display_name', 'display_color', 'wa_name', 'phone_number', 'status']);

        $simulator = app(DemoWhatsappSessionSimulator::class);
        if ($simulator->isDemoTenant()) {
            $sessions = $sessions->map(
                fn (WhatsappSession $session): WhatsappSession => $simulator->markConnected($session),
            );
        }

        return $sessions->toArray();
    }

    private function archivedChatsCount(User $user): int
    {
        return $this->applyAccessScope(
            Chat::query()
                ->where('is_archived', true)
                ->withOperatorVisibleActivity(),
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

    private function unreadChatsCountMine(User $user): int
    {
        return (int) $this->applyAccessScope(
            Chat::query()
                ->where('is_archived', false)
                ->where('unread_count', '>', 0)
                ->whereHas('assignments', fn (Builder $aq) => $aq->where('user_id', $user->id)),
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
