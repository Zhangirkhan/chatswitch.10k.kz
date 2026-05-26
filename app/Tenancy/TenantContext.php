<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Models\Company;
use RuntimeException;

final class TenantContext
{
    private ?Company $company = null;

    private bool $scopeBypass = false;

    private ?string $slug = null;

    public function isResolved(): bool
    {
        return $this->company !== null;
    }

    public function shouldApplyTenantScope(): bool
    {
        return $this->isResolved() && ! $this->scopeBypass;
    }

    public function bypassScopes(bool $bypass = true): void
    {
        $this->scopeBypass = $bypass;
    }

    public function slug(): ?string
    {
        return $this->slug;
    }

    public function company(): ?Company
    {
        return $this->company;
    }

    public function companyId(): int
    {
        if ($this->company === null) {
            throw new RuntimeException('Tenant context is not resolved.');
        }

        return (int) $this->company->id;
    }

    public function companyIdOrNull(): ?int
    {
        return $this->company?->id;
    }

    public function setCompany(Company $company): void
    {
        $this->company = $company;
        $this->slug = $company->slug;
        $this->scopeBypass = false;
    }

    public function resolveBySlug(string $slug): void
    {
        $company = Company::query()
            ->withoutGlobalScope('tenant')
            ->where('slug', $slug)
            ->where('is_active', true)
            ->first();

        if ($company === null) {
            throw new RuntimeException("Tenant not found: {$slug}");
        }

        $this->setCompany($company);
    }

    public function clear(): void
    {
        $this->company = null;
        $this->slug = null;
        $this->scopeBypass = false;
    }

    public static function parseSlugFromHost(string $host, string $rootDomain): ?string
    {
        $host = strtolower($host);
        $rootDomain = strtolower($rootDomain);

        if ($host === $rootDomain) {
            return null;
        }

        $suffix = '.'.$rootDomain;
        if (! str_ends_with($host, $suffix)) {
            return null;
        }

        $subdomain = substr($host, 0, -strlen($suffix));

        if ($subdomain === '' || str_contains($subdomain, '.')) {
            return null;
        }

        return $subdomain;
    }

    public function isAdminHost(string $host): bool
    {
        $admin = config('tenancy.admin_subdomain', 'app');
        $root = config('tenancy.root_domain', 'accel.kz');

        return strtolower($host) === strtolower("{$admin}.{$root}");
    }

    public function isRootHost(string $host): bool
    {
        return strtolower($host) === strtolower((string) config('tenancy.root_domain', 'accel.kz'));
    }
}
