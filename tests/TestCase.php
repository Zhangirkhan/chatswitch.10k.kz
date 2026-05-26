<?php

declare(strict_types=1);

namespace Tests;

use App\Models\Company;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\URL;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

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

        $this->withoutMiddleware(ValidateCsrfToken::class);
    }
}
