<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Company;
use App\Tenancy\TenantContext;
use Illuminate\Queue\Events\JobProcessing;

final class ApplyTenantToQueue
{
    public function __construct(
        private readonly TenantContext $tenantContext,
    ) {}

    public function handle(JobProcessing $event): void
    {
        try {
            $payload = $event->job->payload();
            $serialized = $payload['data']['command'] ?? null;
            if (! is_string($serialized)) {
                return;
            }

            $command = unserialize($serialized, ['allowed_classes' => true]);
            if (! is_object($command) || ! property_exists($command, 'tenantCompanyId')) {
                return;
            }

            $companyId = $command->tenantCompanyId;
            if (! is_int($companyId) || $companyId <= 0) {
                return;
            }

            $company = Company::query()->withoutGlobalScope('tenant')->find($companyId);
            if ($company !== null) {
                $this->tenantContext->setCompany($company);
            }
        } catch (\Throwable) {
            // Non-job payloads or legacy jobs without tenant context.
        }
    }
}
