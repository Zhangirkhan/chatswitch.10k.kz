<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

use App\Jobs\IssueTenantCertificateJob;
use App\Jobs\VerifyTenantProvisioningJob;
use App\Models\Company;
use App\Models\Plan;
use App\Models\User;
use App\Services\Billing\SubscriptionLifecycleService;
use App\Services\Company\CompanyOnboardingService;
use App\Support\TenantRoles;
use App\Support\PhoneFormatter;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

final class CompanyProvisioningService
{
    public function __construct(
        private readonly CompanyOnboardingService $onboarding,
        private readonly SubscriptionLifecycleService $subscriptions,
    ) {}

    /**
     * @param  array{name: string, slug: string, phone: string, plan_id?: int|null, owner_name: string, owner_email: string, owner_password?: string|null}  $data
     * @return array{company: Company, owner: User, temporary_password: ?string}
     */
    public function create(array $data): array
    {
        $result = DB::transaction(function () use ($data): array {
            $plan = isset($data['plan_id'])
                ? Plan::query()->findOrFail((int) $data['plan_id'])
                : $this->subscriptions->defaultPlan();

            $temporaryPassword = $data['owner_password'] ?? Str::password(12);

            $company = Company::query()->create([
                'name' => $data['name'],
                'slug' => strtolower($data['slug']),
                'phone' => PhoneFormatter::normalize($data['phone']) ?? $data['phone'],
                'is_active' => true,
                'plan_id' => $plan->id,
                'subscription_status' => 'trial',
            ]);

            $owner = User::query()->withoutGlobalScope('tenant')->create([
                'name' => $data['owner_name'],
                'email' => $data['owner_email'],
                'password' => Hash::make($temporaryPassword),
                'company_id' => $company->id,
                'is_active' => true,
                'is_super_admin' => false,
            ]);

            $context = app(TenantContext::class);
            $previousCompany = $context->company();
            $context->setCompany($company);
            setPermissionsTeamId($company->id);

            try {
                TenantRoles::ensureDefaultRolesForCompany($company);
                TenantRoles::syncForCompany($owner, $company->id, 'administrator');

                $company->update(['owner_user_id' => $owner->id]);

                $this->subscriptions->startTrial($company->fresh(), $plan);
                $this->onboarding->bootstrap($company->fresh(), $owner);
            } finally {
                if ($previousCompany instanceof Company) {
                    $context->setCompany($previousCompany);
                    setPermissionsTeamId($previousCompany->id);
                } else {
                    $context->clear();
                }
            }

            return [
                'company' => $company->fresh(['plan', 'owner']),
                'owner' => $owner,
                'temporary_password' => $data['owner_password'] ?? $temporaryPassword,
            ];
        });

        IssueTenantCertificateJob::dispatch($result['company']->slug);
        VerifyTenantProvisioningJob::dispatch($result['company']->id);
        $this->syncNginxKnownTenantsMap();

        app(\App\Services\AI\AiReadinessService::class)->invalidateCounts($result['company']->id);

        app(\App\Services\SuperAdmin\CompanyModuleSettingsService::class)
            ->ensureDefaults($result['company']);

        return $result;
    }

    private function syncNginxKnownTenantsMap(): void
    {
        try {
            app(TenantNginxMapService::class)->writeMapFile();
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
