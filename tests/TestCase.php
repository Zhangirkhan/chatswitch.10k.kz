<?php

declare(strict_types=1);

namespace Tests;

use App\Models\Company;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['accel.ai_idle_reply_minutes' => 0]);

        $this->withoutMiddleware(ValidateCsrfToken::class);

        if (! Schema::hasTable('companies')) {
            return;
        }

        $slug = (string) config('tenancy.fallback_slug', 'demo');
        $company = Company::query()
            ->withoutGlobalScope('tenant')
            ->where('slug', $slug)
            ->first();

        if ($company === null) {
            $company = Company::query()->withoutGlobalScope('tenant')->create([
                'name' => 'Test Company',
                'slug' => $slug,
                'is_active' => true,
                'subscription_status' => 'active',
            ]);
        }

        app(TenantContext::class)->setCompany($company);

        $host = $slug.'.'.config('tenancy.root_domain', 'accel.kz');
        $rootUrl = 'http://'.$host;
        $this->withServerVariables(['HTTP_HOST' => $host]);
        URL::forceRootUrl($rootUrl);
        URL::defaults(['tenant' => $slug]);
    }

    protected function switchTenant(Company $company): void
    {
        app(TenantContext::class)->setCompany($company);

        $host = $company->slug.'.'.config('tenancy.root_domain', 'accel.kz');
        $rootUrl = 'http://'.$host;
        $this->withServerVariables(['HTTP_HOST' => $host]);
        URL::forceRootUrl($rootUrl);
        URL::defaults(['tenant' => $company->slug]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function createTenantCompany(array $attributes = []): Company
    {
        $company = Company::query()->create(array_merge([
            'name' => 'Test Company',
            'is_active' => true,
            'subscription_status' => 'active',
        ], $attributes));

        $this->switchTenant($company);

        return $company;
    }
}
