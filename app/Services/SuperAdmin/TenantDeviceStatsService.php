<?php

declare(strict_types=1);

namespace App\Services\SuperAdmin;

use App\Models\Company;
use App\Models\User;
use App\Models\UserDevice;
use App\Models\WhatsappSession;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

final class TenantDeviceStatsService
{
    private const ACTIVE_MOBILE_DAYS = 30;

    /** @var list<string> */
    private const WHATSAPP_CONNECTED_STATUSES = ['connected', 'ready', 'authenticated'];

    /**
     * @return array{
     *     active_users: int,
     *     mobile_devices: array{
     *         total: int,
     *         android: int,
     *         ios: int,
     *         users_with_device: int,
     *         users_without_device: int,
     *     },
     *     mobile_sessions: array{
     *         total_tokens: int,
     *         active_30d: int,
     *         users_with_active_token: int,
     *     },
     *     whatsapp_sessions: array{
     *         total: int,
     *         connected: int,
     *     },
     *     web_sessions: array{
     *         active_now: int,
     *         users_online: int,
     *     },
     * }
     */
    public function forCompany(Company $company): array
    {
        $companyId = (int) $company->id;
        $activeUserIds = $this->activeUserIdsForCompany($companyId);

        return [
            'active_users' => count($activeUserIds),
            'mobile_devices' => $this->mobileDevicesForCompany($companyId, $activeUserIds),
            'mobile_sessions' => $this->mobileSessionsForUsers($activeUserIds),
            'whatsapp_sessions' => $this->whatsappSessionsForCompany($companyId),
            'web_sessions' => $this->webSessionsForUsers($activeUserIds),
        ];
    }

    /**
     * @param  Builder<Company>  $companyQuery
     * @return array{
     *     active_users: int,
     *     mobile_devices_total: int,
     *     mobile_sessions_active_30d: int,
     *     whatsapp_connected: int,
     *     web_sessions_active: int,
     *     tenants_with_mobile: int,
     * }
     */
    public function forPlatform(Builder $companyQuery): array
    {
        $companyIds = (clone $companyQuery)->pluck('id')->map(fn ($id) => (int) $id)->all();

        if ($companyIds === []) {
            return [
                'active_users' => 0,
                'mobile_devices_total' => 0,
                'mobile_sessions_active_30d' => 0,
                'whatsapp_connected' => 0,
                'web_sessions_active' => 0,
                'tenants_with_mobile' => 0,
            ];
        }

        $activeUserIds = User::query()
            ->withoutGlobalScope('tenant')
            ->whereIn('company_id', $companyIds)
            ->where('is_active', true)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $mobileDevicesTotal = UserDevice::query()
            ->withoutGlobalScope('tenant')
            ->whereIn('company_id', $companyIds)
            ->count();

        $tenantsWithMobile = (int) UserDevice::query()
            ->withoutGlobalScope('tenant')
            ->whereIn('company_id', $companyIds)
            ->distinct('company_id')
            ->count('company_id');

        $whatsappConnected = WhatsappSession::query()
            ->withoutGlobalScope('tenant')
            ->whereIn('company_id', $companyIds)
            ->whereIn('status', self::WHATSAPP_CONNECTED_STATUSES)
            ->count();

        return [
            'active_users' => count($activeUserIds),
            'mobile_devices_total' => $mobileDevicesTotal,
            'mobile_sessions_active_30d' => $this->countActiveMobileTokens($activeUserIds),
            'whatsapp_connected' => $whatsappConnected,
            'web_sessions_active' => $this->countActiveWebSessions($activeUserIds),
            'tenants_with_mobile' => $tenantsWithMobile,
        ];
    }

    /**
     * @return list<int>
     */
    private function activeUserIdsForCompany(int $companyId): array
    {
        return User::query()
            ->withoutGlobalScope('tenant')
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * @param  list<int>  $activeUserIds
     * @return array{
     *     total: int,
     *     android: int,
     *     ios: int,
     *     users_with_device: int,
     *     users_without_device: int,
     * }
     */
    private function mobileDevicesForCompany(int $companyId, array $activeUserIds): array
    {
        $devices = UserDevice::query()
            ->withoutGlobalScope('tenant')
            ->where('company_id', $companyId)
            ->get(['user_id', 'platform']);

        $usersWithDevice = $devices
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->count();

        return [
            'total' => $devices->count(),
            'android' => $devices->where('platform', 'android')->count(),
            'ios' => $devices->where('platform', 'ios')->count(),
            'users_with_device' => $usersWithDevice,
            'users_without_device' => max(0, count($activeUserIds) - $usersWithDevice),
        ];
    }

    /**
     * @param  list<int>  $userIds
     * @return array{
     *     total_tokens: int,
     *     active_30d: int,
     *     users_with_active_token: int,
     * }
     */
    private function mobileSessionsForUsers(array $userIds): array
    {
        if ($userIds === []) {
            return [
                'total_tokens' => 0,
                'active_30d' => 0,
                'users_with_active_token' => 0,
            ];
        }

        $tokens = PersonalAccessToken::query()
            ->where('tokenable_type', User::class)
            ->whereIn('tokenable_id', $userIds)
            ->get(['tokenable_id', 'last_used_at', 'created_at']);

        $activeSince = now()->subDays(self::ACTIVE_MOBILE_DAYS);
        $activeTokens = $tokens->filter(fn (PersonalAccessToken $token): bool => $this->isMobileTokenActive($token, $activeSince));

        return [
            'total_tokens' => $tokens->count(),
            'active_30d' => $activeTokens->count(),
            'users_with_active_token' => $activeTokens
                ->pluck('tokenable_id')
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->count(),
        ];
    }

    /**
     * @param  list<int>  $userIds
     */
    private function countActiveMobileTokens(array $userIds): int
    {
        if ($userIds === []) {
            return 0;
        }

        $activeSince = now()->subDays(self::ACTIVE_MOBILE_DAYS);

        return PersonalAccessToken::query()
            ->where('tokenable_type', User::class)
            ->whereIn('tokenable_id', $userIds)
            ->where(function ($query) use ($activeSince): void {
                $query->where('last_used_at', '>=', $activeSince)
                    ->orWhere(function ($nested) use ($activeSince): void {
                        $nested->whereNull('last_used_at')
                            ->where('created_at', '>=', $activeSince);
                    });
            })
            ->count();
    }

    /**
     * @return array{total: int, connected: int}
     */
    private function whatsappSessionsForCompany(int $companyId): array
    {
        $total = WhatsappSession::query()
            ->withoutGlobalScope('tenant')
            ->where('company_id', $companyId)
            ->count();

        $connected = WhatsappSession::query()
            ->withoutGlobalScope('tenant')
            ->where('company_id', $companyId)
            ->whereIn('status', self::WHATSAPP_CONNECTED_STATUSES)
            ->count();

        return [
            'total' => $total,
            'connected' => $connected,
        ];
    }

    /**
     * @param  list<int>  $userIds
     * @return array{active_now: int, users_online: int}
     */
    private function webSessionsForUsers(array $userIds): array
    {
        if ($userIds === []) {
            return [
                'active_now' => 0,
                'users_online' => 0,
            ];
        }

        $cutoff = now()->subMinutes((int) config('session.lifetime', 120))->getTimestamp();

        $sessions = DB::table('sessions')
            ->whereIn('user_id', $userIds)
            ->where('last_activity', '>=', $cutoff)
            ->get(['user_id']);

        return [
            'active_now' => $sessions->count(),
            'users_online' => $sessions
                ->pluck('user_id')
                ->filter(fn ($id) => $id !== null)
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->count(),
        ];
    }

    /**
     * @param  list<int>  $userIds
     */
    private function countActiveWebSessions(array $userIds): int
    {
        if ($userIds === []) {
            return 0;
        }

        $cutoff = now()->subMinutes((int) config('session.lifetime', 120))->getTimestamp();

        return (int) DB::table('sessions')
            ->whereIn('user_id', $userIds)
            ->where('last_activity', '>=', $cutoff)
            ->count();
    }

    private function isMobileTokenActive(PersonalAccessToken $token, \Illuminate\Support\Carbon $activeSince): bool
    {
        if ($token->last_used_at !== null) {
            return $token->last_used_at->greaterThanOrEqualTo($activeSince);
        }

        return $token->created_at !== null && $token->created_at->greaterThanOrEqualTo($activeSince);
    }
}
