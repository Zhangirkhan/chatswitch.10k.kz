<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Models\User;
use App\Models\WhatsappSession;
use App\Services\Company\CompanyOnboardingService;
use App\Support\TenantCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class SettingsReadinessMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['funnel.enforce_settings_readiness_gate' => true]);

        foreach (['administrator', 'manager', 'employee'] as $role) {
            Role::findOrCreate($role);
        }
    }

    public function test_admin_can_open_users_without_whatsapp(): void
    {
        $this->withoutVite();

        $admin = User::factory()->create();
        $admin->assignRole('administrator');

        $this->actingAs($admin)
            ->get(route('settings.users'))
            ->assertOk();
    }

    public function test_admin_can_open_departments_without_whatsapp(): void
    {
        $this->withoutVite();

        $admin = User::factory()->create();
        $admin->assignRole('administrator');

        $this->actingAs($admin)
            ->get(route('settings.departments'))
            ->assertOk();
    }

    public function test_admin_can_open_system_settings_without_whatsapp(): void
    {
        $this->withoutVite();

        $admin = User::factory()->create();
        $admin->assignRole('administrator');

        $this->actingAs($admin)
            ->get(route('settings.system'))
            ->assertOk();
    }

    public function test_admin_can_open_funnels_when_ai_not_ready(): void
    {
        $this->withoutVite();

        $admin = User::factory()->create();
        $admin->assignRole('administrator');

        $this->actingAs($admin)
            ->get(route('settings.funnels'))
            ->assertOk();
    }

    public function test_admin_can_open_knowledge_when_ai_not_ready(): void
    {
        $this->withoutVite();

        $admin = User::factory()->create();
        $admin->assignRole('administrator');

        $this->actingAs($admin)
            ->get(route('settings.knowledge.rules'))
            ->assertOk();
    }

    public function test_admin_redirected_from_ai_quality_when_ai_not_ready(): void
    {
        $this->withoutVite();

        $admin = User::factory()->create();
        $admin->assignRole('administrator');

        $this->actingAs($admin)
            ->get(route('settings.ai-quality'))
            ->assertRedirect(route('settings.onboarding'));
    }

    public function test_admin_can_open_connections_before_ai_ready(): void
    {
        $this->withoutVite();

        $admin = User::factory()->create();
        $admin->assignRole('administrator');

        $this->actingAs($admin)
            ->get(route('settings.connections'))
            ->assertOk();
    }

    public function test_admin_can_bootstrap_connections_before_ai_ready(): void
    {
        Http::fake([
            '127.0.0.1:3050/health' => Http::response(['status' => 'ok'], 200),
        ]);

        $admin = User::factory()->create();
        $admin->assignRole('administrator');

        $this->actingAs($admin)
            ->getJson(route('settings.connections.bootstrap'))
            ->assertOk()
            ->assertJsonPath('whatsappServiceReachable', true);
    }

    public function test_admin_can_open_funnels_after_bootstrap(): void
    {
        $this->withoutVite();

        $admin = User::factory()->create();
        $admin->assignRole('administrator');

        $company = TenantCompany::ensureExists();
        $admin->forceFill(['company_id' => $company->id])->save();

        app(CompanyOnboardingService::class)->bootstrap($company, $admin);
        WhatsappSession::factory()->create(['status' => 'connected']);

        $this->actingAs($admin->fresh())
            ->get(route('settings.funnels'))
            ->assertOk();
    }

    public function test_onboarding_complete_redirects_when_not_ready(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('administrator');

        $this->actingAs($admin)
            ->post(route('settings.onboarding.complete'))
            ->assertRedirect(route('settings.onboarding'));
    }
}
