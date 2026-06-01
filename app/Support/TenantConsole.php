<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Company;
use App\Tenancy\TenantContext;
use Closure;

final class TenantConsole
{
    public function __construct(
        private readonly TenantContext $tenantContext,
    ) {}

    /**
     * @param  Closure(Company): void  $callback
     */
    public function eachActiveCompany(Closure $callback): void
    {
        Company::query()
            ->withoutGlobalScope('tenant')
            ->where('is_active', true)
            ->orderBy('id')
            ->chunkById(50, function ($companies) use ($callback): void {
                foreach ($companies as $company) {
                    if (! $company instanceof Company) {
                        continue;
                    }

                    $this->tenantContext->setCompany($company);

                    try {
                        $callback($company);
                    } finally {
                        $this->tenantContext->clear();
                    }
                }
            });
    }
}
