<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\TenantRoleService;
use App\Support\TenantRoles;
use Illuminate\Console\Command;
use Spatie\Permission\PermissionRegistrar;

final class TenantsMigrateRolesCommand extends Command
{
    protected $signature = 'tenants:migrate-roles {--company= : ID компании для точечной миграции}';

    protected $description = 'Перенос глобальных ролей на tenant-scoped роли с permissions';

    public function handle(TenantRoleService $tenantRoleService): int
    {
        TenantRoles::ensurePermissionsSeeded();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $companyId = $this->option('company');
        $query = Company::query()->withoutGlobalScope('tenant');

        if (is_string($companyId) && $companyId !== '') {
            $query->whereKey((int) $companyId);
        }

        $companies = $query->get();

        if ($companies->isEmpty()) {
            $this->warn('Компании для миграции не найдены.');

            return self::SUCCESS;
        }

        foreach ($companies as $company) {
            $this->info("Миграция ролей: {$company->name} (#{$company->id})");
            $tenantRoleService->migrateCompany($company);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->info('Миграция ролей завершена.');

        return self::SUCCESS;
    }
}
