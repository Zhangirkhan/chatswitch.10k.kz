<?php

declare(strict_types=1);

namespace Tests\Feature\SuperAdmin;

use App\Models\Company;
use App\Models\SystemSetting;
use App\Models\User;
use App\Support\CompanyModules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

final class CompanyModuleSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function adminHost(): string
    {
        return config('tenancy.admin_subdomain', 'app').'.'.config('tenancy.root_domain', 'accel.kz');
    }

    public function test_super_admin_can_update_company_modules(): void
    {
        $company = Company::query()->withoutGlobalScope('tenant')->create([
            'name' => 'Modules Co',
            'slug' => 'modules-co',
            'is_active' => true,
            'subscription_status' => 'trial',
        ]);

        foreach (CompanyModules::defaultValues() as $key => $value) {
            SystemSetting::query()->withoutGlobalScope('tenant')->create([
                'company_id' => $company->id,
                'key' => $key,
                'value' => $value,
            ]);
        }

        SystemSetting::getValue('module_funnels', null, $company->id);

        $admin = User::factory()->create(['is_super_admin' => true, 'company_id' => null]);
        $host = $this->adminHost();
        URL::forceRootUrl('https://'.$host);

        $this->actingAs($admin)->put("https://{$host}/companies/{$company->id}/modules", [
            'modules' => [
                'module_funnels' => false,
                'module_calendar' => true,
            ],
        ])->assertRedirect()->assertSessionHas('success');

        $this->assertSame('off', SystemSetting::getValue('module_funnels', null, $company->id));
        $this->assertSame('on', SystemSetting::getValue('module_calendar', null, $company->id));

        $this->assertDatabaseHas('super_admin_audit_logs', [
            'company_id' => $company->id,
            'action' => 'company.modules_updated',
        ]);
    }

    public function test_company_show_includes_modules_payload(): void
    {
        $company = Company::query()->withoutGlobalScope('tenant')->create([
            'name' => 'Show Modules Co',
            'slug' => 'show-modules-co',
            'is_active' => true,
            'subscription_status' => 'trial',
        ]);

        $admin = User::factory()->create(['is_super_admin' => true, 'company_id' => null]);
        $host = $this->adminHost();
        URL::forceRootUrl('https://'.$host);

        $this->actingAs($admin)->get("https://{$host}/companies/{$company->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('companyModules', count(CompanyModules::keys())));
    }
}
