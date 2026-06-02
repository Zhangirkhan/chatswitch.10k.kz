<?php

declare(strict_types=1);

namespace Tests\Feature\SuperAdmin;

use App\Models\Company;
use App\Models\FunnelStage;
use App\Models\User;
use App\Models\WhatsappSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

final class DemoTenantPopulationTest extends TestCase
{
    use RefreshDatabase;

    private function adminHost(): string
    {
        return config('tenancy.admin_subdomain', 'app').'.'.config('tenancy.root_domain', 'accel.kz');
    }

    public function test_populate_demo_tenant_rebuilds_structure(): void
    {
        $demoSlug = config('tenancy.fallback_slug', 'demo');

        $admin = User::factory()->create(['is_super_admin' => true, 'company_id' => null]);
        $host = $this->adminHost();
        URL::forceRootUrl('https://'.$host);

        $this->actingAs($admin)->post("https://{$host}/companies/populate-demo")
            ->assertRedirect()
            ->assertSessionHas('success');

        $company = Company::query()->where('slug', $demoSlug)->first();
        $this->assertNotNull($company);
        $this->assertSame('Accel Demo', $company->name);
        $this->assertSame('active', $company->subscription_status);

        $this->assertDatabaseHas('users', ['email' => 'demo@accel.kz', 'company_id' => $company->id]);
        $this->assertSame(3, WhatsappSession::query()->where('company_id', $company->id)->count());
        $this->assertSame(
            3,
            WhatsappSession::query()->where('company_id', $company->id)->where('status', 'connected')->count(),
        );
        $this->assertGreaterThanOrEqual(10, FunnelStage::query()->whereHas('funnel', fn ($q) => $q->where('company_id', $company->id))->count());
    }
}
