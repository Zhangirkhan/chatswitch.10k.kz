<?php

declare(strict_types=1);

namespace App\Services\SuperAdmin;

use App\Models\Company;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

final class CompanyOwnerService
{
    public function __construct(
        private readonly SuperAdminAuditLogger $audit,
    ) {}

    public function resolveDefaultAdministrator(Company $company): ?User
    {
        return $this->withCompanyTeam($company, static function () use ($company): ?User {
            return User::query()
                ->withoutGlobalScope('tenant')
                ->where('company_id', $company->id)
                ->where('is_active', true)
                ->whereHas('roles', fn ($q) => $q->where('name', 'administrator'))
                ->orderBy('id')
                ->first();
        });
    }

    public function assign(Company $company, User $user, ?User $actor = null): void
    {
        $this->assertCanBeOwner($company, $user);

        $previousOwnerId = $company->owner_user_id;
        $company->update(['owner_user_id' => $user->id]);

        $this->audit->log($company, $actor, 'company.owner_assigned', $user, [
            'owner_user_id' => $user->id,
            'owner_email' => $user->email,
            'previous_owner_user_id' => $previousOwnerId,
        ]);
    }

    /**
     * @return 'assigned'|'skipped_has_owner'|'skipped_no_admin'
     */
    public function backfill(Company $company, bool $dryRun = false): string
    {
        if ($company->owner_user_id !== null) {
            return 'skipped_has_owner';
        }

        $admin = $this->resolveDefaultAdministrator($company);
        if ($admin === null) {
            return 'skipped_no_admin';
        }

        if (! $dryRun) {
            $this->assign($company, $admin);
        }

        return 'assigned';
    }

    public function assertCanBeOwner(Company $company, User $user): void
    {
        if ((int) $user->company_id !== (int) $company->id) {
            throw ValidationException::withMessages([
                'user_id' => 'Пользователь не принадлежит этой компании.',
            ]);
        }

        if (! $user->is_active) {
            throw ValidationException::withMessages([
                'user_id' => 'Владельцем может быть только активный пользователь.',
            ]);
        }

        $isAdministrator = $this->withCompanyTeam($company, static fn (): bool => $user->hasRole('administrator'));
        if (! $isAdministrator) {
            throw ValidationException::withMessages([
                'user_id' => 'Владельцем может быть только пользователь с ролью administrator.',
            ]);
        }
    }

    private function withCompanyTeam(Company $company, callable $callback): mixed
    {
        $previousTeamId = app(PermissionRegistrar::class)->getPermissionsTeamId();
        setPermissionsTeamId($company->id);

        try {
            return $callback();
        } finally {
            setPermissionsTeamId($previousTeamId);
        }
    }
}
