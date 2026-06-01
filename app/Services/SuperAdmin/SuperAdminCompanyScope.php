<?php

declare(strict_types=1);

namespace App\Services\SuperAdmin;

use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

final class SuperAdminCompanyScope
{
    public const SCOPE_GLOBAL = 'global';

    public const SCOPE_SANDBOX = 'sandbox';

    public function isSandboxSuperAdmin(?User $user): bool
    {
        return $user !== null
            && $user->is_super_admin
            && $this->scope($user) === self::SCOPE_SANDBOX;
    }

    public function isGlobalSuperAdmin(?User $user): bool
    {
        return $user !== null
            && $user->is_super_admin
            && ! $this->isSandboxSuperAdmin($user);
    }

    public function scope(User $user): string
    {
        $scope = strtolower(trim((string) ($user->super_admin_scope ?? self::SCOPE_GLOBAL)));

        return $scope === self::SCOPE_SANDBOX ? self::SCOPE_SANDBOX : self::SCOPE_GLOBAL;
    }

    /**
     * @param  Builder<Company>  $query
     * @return Builder<Company>
     */
    public function applyToCompaniesQuery(Builder $query, ?User $user): Builder
    {
        if (! $this->isSandboxSuperAdmin($user)) {
            return $query;
        }

        return $query->where('companies.provisioned_by_user_id', $user->id);
    }

    public function canManage(?User $user, Company $company): bool
    {
        if ($user === null || ! $user->is_super_admin) {
            return false;
        }

        if (! $this->isSandboxSuperAdmin($user)) {
            return true;
        }

        return (int) $company->provisioned_by_user_id === (int) $user->id;
    }

    public function ensureCanManage(?User $user, Company $company): void
    {
        if (! $this->canManage($user, $company)) {
            abort(403, 'Нет доступа к этой компании.');
        }
    }

    public function ensureGlobalSuperAdmin(?User $user): void
    {
        if (! $this->isGlobalSuperAdmin($user)) {
            abort(403, 'Этот раздел доступен только глобальным супер-администраторам.');
        }
    }
}
