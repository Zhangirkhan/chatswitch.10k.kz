<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\SuperAdmin\CompanyOwnerService;
use Illuminate\Console\Command;

final class BackfillTenantOwnersCommand extends Command
{
    protected $signature = 'tenants:backfill-owners
                            {--company= : slug или ID одной компании}
                            {--dry-run : Показать, что будет назначено, без записи в БД}';

    protected $description = 'Назначить владельца (owner_user_id) тенантам, у которых он не задан';

    public function handle(CompanyOwnerService $owners): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $companyFilter = $this->option('company');

        $query = Company::query()
            ->withoutGlobalScope('tenant')
            ->whereNull('owner_user_id')
            ->orderBy('id');

        if (is_string($companyFilter) && $companyFilter !== '') {
            $company = $this->resolveCompany($companyFilter);
            if ($company === null) {
                $this->error("Компания не найдена: {$companyFilter}");

                return self::FAILURE;
            }

            if ($company->owner_user_id !== null) {
                $this->info("У компании {$company->slug} (#{$company->id}) владелец уже назначен.");

                return self::SUCCESS;
            }

            $query->whereKey($company->id);
        }

        $companies = $query->get();

        if ($companies->isEmpty()) {
            $this->info('Нет компаний без назначенного владельца.');

            return self::SUCCESS;
        }

        $assigned = 0;
        $skippedNoAdmin = 0;

        foreach ($companies as $company) {
            $result = $owners->backfill($company, $dryRun);

            if ($result === 'assigned') {
                $assigned++;
                $owner = $owners->resolveDefaultAdministrator($company);
                $label = $owner !== null ? "{$owner->email} (#{$owner->id})" : 'administrator';
                $prefix = $dryRun ? '[dry-run] ' : '';
                $this->line("<fg=green>{$prefix}✓</> {$company->slug} (#{$company->id}) → {$label}");

                continue;
            }

            if ($result === 'skipped_no_admin') {
                $skippedNoAdmin++;
                $this->line("<fg=yellow>✗</> {$company->slug} (#{$company->id}) — нет активного administrator");

                continue;
            }
        }

        $this->newLine();
        $this->info("Проверено: {$companies->count()}, назначено: {$assigned}, без administrator: {$skippedNoAdmin}".($dryRun ? ' (dry-run)' : ''));

        return $skippedNoAdmin > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function resolveCompany(string $tenant): ?Company
    {
        if (ctype_digit($tenant)) {
            return Company::query()->withoutGlobalScope('tenant')->find((int) $tenant);
        }

        return Company::query()->withoutGlobalScope('tenant')->where('slug', strtolower($tenant))->first();
    }
}
