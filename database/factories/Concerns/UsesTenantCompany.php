<?php

declare(strict_types=1);

namespace Database\Factories\Concerns;

use App\Tenancy\TenantContext;

trait UsesTenantCompany
{
    protected function tenantCompanyId(): int
    {
        $context = app(TenantContext::class);

        return $context->companyIdOrNull() ?? 1;
    }
}
