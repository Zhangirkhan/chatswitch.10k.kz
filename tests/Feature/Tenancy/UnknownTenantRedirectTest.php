<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class UnknownTenantRedirectTest extends TestCase
{
    use RefreshDatabase;

    public function test_unknown_tenant_subdomain_redirects_to_landing_home(): void
    {
        $response = $this->get('https://nonexistent-tenant-xyz.accel.kz/login');

        $response->assertRedirect('https://accel.kz/');
    }

    public function test_unknown_tenant_json_returns_404(): void
    {
        $response = $this->postJson('https://no-such-company.accel.kz/api/v1/auth/login', [
            'email' => 'nobody@example.com',
            'password' => 'secret',
        ]);

        $response->assertNotFound();
    }
}
