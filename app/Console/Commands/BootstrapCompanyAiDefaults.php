<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\Company\CompanyOnboardingService;
use Illuminate\Console\Command;

final class BootstrapCompanyAiDefaults extends Command
{
    protected $signature = 'companies:bootstrap-ai-defaults {company_id? : ID конкретной компании}';

    protected $description = 'Create default AI funnels, rules, knowledge and catalog records for companies';

    public function handle(CompanyOnboardingService $onboarding): int
    {
        $companyId = $this->argument('company_id');
        $query = Company::query()->orderBy('id');

        if ($companyId !== null) {
            $query->whereKey((int) $companyId);
        }

        $count = 0;
        $query->chunkById(100, function ($companies) use ($onboarding, &$count): void {
            foreach ($companies as $company) {
                $onboarding->bootstrap($company);
                $count++;
                $this->line("Bootstrapped company #{$company->id}: {$company->name}");
            }
        });

        $this->info("Bootstrapped {$count} companies.");

        return self::SUCCESS;
    }
}
