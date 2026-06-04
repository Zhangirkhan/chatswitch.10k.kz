<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Models\Company;
use App\Models\Funnel;
use App\Models\SystemSetting;
use App\Models\User;
use App\Support\TenantCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class FunnelsActiveApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['administrator', 'manager', 'employee'] as $roleName) {
            Role::findOrCreate($roleName);
        }
        SystemSetting::setValue('module_funnels', 'on');
        TenantCompany::ensureExists();
    }

    public function test_employee_receives_active_funnels_list(): void
    {
        $company = Company::query()->findOrFail(TenantCompany::id());
        $user = User::factory()->create(['company_id' => $company->id]);
        $user->assignRole('employee');

        $funnel = Funnel::query()->create([
            'company_id' => $company->id,
            'name' => 'Продажи',
            'color' => '#01b964',
            'description' => 'Основная',
            'is_active' => true,
            'position' => 1,
        ]);

        Funnel::query()->create([
            'company_id' => $company->id,
            'name' => 'Архивная',
            'is_active' => false,
            'position' => 2,
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/funnels/active')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $funnel->id)
            ->assertJsonPath('data.0.name', 'Продажи')
            ->assertJsonPath('data.0.color', '#01b964');
    }

    public function test_returns_403_when_funnels_module_disabled(): void
    {
        SystemSetting::setValue('module_funnels', 'off');

        $user = User::factory()->create();
        $user->assignRole('manager');
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/funnels/active')->assertForbidden();
    }
}
