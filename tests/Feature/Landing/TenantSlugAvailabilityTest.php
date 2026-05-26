<?php

declare(strict_types=1);

namespace Tests\Feature\Landing;

use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

final class TenantSlugAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    private function landingHost(): string
    {
        return config('tenancy.root_domain', 'accel.kz');
    }

    public function test_available_slug_returns_true(): void
    {
        $host = $this->landingHost();
        URL::forceRootUrl('https://'.$host);

        $this->withServerVariables(['HTTP_HOST' => $host])
            ->getJson("https://{$host}/check-tenant-slug?slug=my-new-company")
            ->assertOk()
            ->assertJson([
                'available' => true,
                'reason' => null,
                'slug' => 'my-new-company',
            ]);
    }

    public function test_taken_slug_returns_false(): void
    {
        Company::query()->withoutGlobalScope('tenant')->create([
            'name' => 'Taken Co',
            'slug' => 'taken-co',
            'is_active' => true,
            'subscription_status' => 'trial',
        ]);

        $host = $this->landingHost();
        URL::forceRootUrl('https://'.$host);

        $this->withServerVariables(['HTTP_HOST' => $host])
            ->getJson("https://{$host}/check-tenant-slug?slug=taken-co")
            ->assertOk()
            ->assertJson([
                'available' => false,
                'reason' => 'taken',
            ]);
    }

    public function test_reserved_slug_returns_false(): void
    {
        $host = $this->landingHost();
        URL::forceRootUrl('https://'.$host);

        $this->withServerVariables(['HTTP_HOST' => $host])
            ->getJson("https://{$host}/check-tenant-slug?slug=app")
            ->assertOk()
            ->assertJson([
                'available' => false,
                'reason' => 'reserved',
            ]);
    }

    public function test_invalid_slug_returns_false(): void
    {
        $host = $this->landingHost();
        URL::forceRootUrl('https://'.$host);

        $this->withServerVariables(['HTTP_HOST' => $host])
            ->getJson("https://{$host}/check-tenant-slug?slug=-bad-")
            ->assertOk()
            ->assertJson([
                'available' => false,
                'reason' => 'invalid',
            ]);
    }
}
