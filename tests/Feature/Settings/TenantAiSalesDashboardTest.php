<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Models\Chat;
use App\Models\Company;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class TenantAiSalesDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['administrator', 'manager', 'employee'] as $role) {
            Role::findOrCreate($role);
        }
    }

    public function test_administrator_can_open_tenant_ai_sales_dashboard(): void
    {
        $this->withoutVite();

        $company = $this->createTenantCompany(['name' => 'Tenant Co', 'slug' => 'tenant-co']);

        $admin = User::factory()->create(['company_id' => $company->id]);
        $admin->assignRole('administrator');

        $chat = Chat::factory()->create([
            'company_id' => $company->id,
            'ai_enabled' => true,
            'sales_state' => ['qualified' => true],
        ]);

        Message::factory()->create([
            'chat_id' => $chat->id,
            'direction' => 'inbound',
            'message_timestamp' => now()->subDay(),
        ]);

        $this->actingAs($admin)
            ->get(route('settings.ai-sales'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Settings/AiSalesDashboard')
                ->has('metrics.kpis', 12)
                ->where('metrics.summary.cohort_size', 1)
                ->where('metrics.filters.company_id', $company->id));
    }

    public function test_manager_cannot_open_tenant_ai_sales_dashboard(): void
    {
        $company = $this->createTenantCompany(['name' => 'Tenant Co 2', 'slug' => 'tenant-co-2']);

        $manager = User::factory()->create(['company_id' => $company->id]);
        $manager->assignRole('manager');

        $this->actingAs($manager)
            ->get(route('settings.ai-sales'))
            ->assertForbidden();
    }
}
