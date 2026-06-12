<?php

declare(strict_types=1);

namespace Tests\Feature\SuperAdmin;

use App\Models\Chat;
use App\Models\Company;
use App\Models\DealOutcome;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class AiSalesDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('administrator', 'web');
        Carbon::setTestNow(Carbon::parse('2026-06-12 12:00:00', 'Asia/Almaty'));
    }

    private function adminHost(): string
    {
        return config('tenancy.admin_subdomain', 'app').'.'.config('tenancy.root_domain', 'accel.kz');
    }

    private function globalSuperAdmin(): User
    {
        return User::factory()->create([
            'is_super_admin' => true,
            'company_id' => null,
            'super_admin_scope' => 'global',
        ]);
    }

    public function test_non_super_admin_cannot_access_ai_sales_dashboard(): void
    {
        URL::forceRootUrl('https://'.$this->adminHost());

        $user = User::factory()->create(['is_super_admin' => false]);

        $this->withoutVite()
            ->actingAs($user)
            ->get('https://'.$this->adminHost().'/ai-sales')
            ->assertRedirect(route('super.login'));
    }

    public function test_super_admin_can_view_ai_sales_dashboard(): void
    {
        URL::forceRootUrl('https://'.$this->adminHost());

        $company = Company::query()->create([
            'name' => 'TRX Demo',
            'slug' => 'trx-demo',
            'is_active' => true,
            'subscription_status' => 'active',
        ]);

        $chat = Chat::factory()->create([
            'company_id' => $company->id,
            'ai_enabled' => true,
            'sales_state' => [
                'qualified' => true,
                'budget_known' => true,
                'decision_maker_known' => false,
            ],
        ]);

        Message::factory()->create([
            'chat_id' => $chat->id,
            'direction' => 'inbound',
            'message_timestamp' => now()->subDay(),
        ]);

        DealOutcome::query()->create([
            'company_id' => $company->id,
            'chat_id' => $chat->id,
            'won' => true,
            'reason' => 'цена устроила',
            'lead_grade' => 'A',
            'closed_at' => now()->subDay(),
        ]);

        DealOutcome::query()->create([
            'company_id' => $company->id,
            'chat_id' => $chat->id,
            'won' => false,
            'reason' => 'цена',
            'lead_grade' => 'B',
            'closed_at' => now()->subDays(2),
        ]);

        DealOutcome::query()->create([
            'company_id' => $company->id,
            'chat_id' => $chat->id,
            'won' => false,
            'reason' => 'срок',
            'lead_grade' => 'C',
            'closed_at' => now()->subDays(3),
        ]);

        $admin = $this->globalSuperAdmin();

        $this->withoutVite()
            ->actingAs($admin)
            ->get('https://'.$this->adminHost().'/ai-sales?period=30d')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('SuperAdmin/AiSalesDashboard')
                ->has('metrics.kpis', 12)
                ->where('metrics.summary.cohort_size', 1)
                ->where('metrics.summary.closed_deals', 3)
                ->has('metrics.lost_reasons')
                ->has('metrics.win_rate_by_grade')
                ->has('metrics.objection_intelligence')
                ->where('companies', fn ($companies) => collect($companies)->contains('id', $company->id)));
    }

    public function test_sandbox_super_admin_only_sees_own_companies_in_filter(): void
    {
        URL::forceRootUrl('https://'.$this->adminHost());

        $sandboxAdmin = User::factory()->create([
            'is_super_admin' => true,
            'company_id' => null,
            'super_admin_scope' => 'sandbox',
        ]);

        $owned = Company::query()->create([
            'name' => 'Owned Tenant',
            'slug' => 'owned-tenant',
            'is_active' => true,
            'subscription_status' => 'active',
            'provisioned_by_user_id' => $sandboxAdmin->id,
        ]);

        Company::query()->create([
            'name' => 'Other Tenant',
            'slug' => 'other-tenant',
            'is_active' => true,
            'subscription_status' => 'active',
            'provisioned_by_user_id' => null,
        ]);

        $this->withoutVite()
            ->actingAs($sandboxAdmin)
            ->get('https://'.$this->adminHost().'/ai-sales')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('companies', 1)
                ->where('companies.0.id', $owned->id));
    }
}
